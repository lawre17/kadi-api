<?php

namespace App\Services;

use App\Events\GameStateUpdated;
use App\Events\TournamentFinished;
use App\Events\TournamentMatchReady;
use App\Events\TournamentUpdate;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\Tournament;
use App\Models\TournamentPlayer;
use App\Models\TournamentTable;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates a tournament over many individual engine matches. Laravel is the
 * brain: it seats active players into tables each round, asks the Node engine to
 * run each table as a normal match, and advances the bracket as tables finish.
 *
 * Phase 1 implements single-elimination: each table yields one winner who
 * advances; everyone else is eliminated. League/survival scoring come later.
 */
class TournamentService
{
    public function __construct(private readonly NodeEngine $engine) {}

    /**
     * Begin a registering tournament: everyone goes active and round 1 is seated.
     */
    public function start(Tournament $tournament): void
    {
        if ($tournament->status !== 'registering') {
            return;
        }

        $userIds = $tournament->players()->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        $tournament->players()->update(['status' => 'active']);
        $tournament->update(['status' => 'running', 'current_round' => 1]);

        $this->glog('tournament started', [
            'tournament' => $tournament->id,
            'players' => count($userIds),
            'format' => $tournament->format,
        ]);

        $this->seatRound($tournament->fresh(), 1, $userIds);
    }

    /**
     * Record a finished table's result and advance the bracket. Called from the
     * match-finish hook. Serialized per-tournament so two tables finishing at
     * once can't double-advance the round.
     */
    public function recordTableResult(string $matchId, ?int $winnerUserId): void
    {
        $decision = DB::transaction(function () use ($matchId, $winnerUserId) {
            $table = TournamentTable::where('match_id', $matchId)->lockForUpdate()->first();
            if (! $table || $table->status === 'finished') {
                return null;
            }
            $tournament = Tournament::whereKey($table->tournament_id)->lockForUpdate()->first();
            if (! $tournament) {
                return null;
            }

            $seated = array_map('intval', $table->player_user_ids ?? []);
            // Resolve the winner; fall back to the first seat for the rare
            // all-forfeit case where the engine reports no human winner.
            $winner = $winnerUserId ?? ($seated[0] ?? null);

            $table->update(['status' => 'finished', 'winner_user_id' => $winner]);

            // Single-elimination: losers are out this round.
            foreach ($seated as $uid) {
                if ($uid === (int) $winner) {
                    continue;
                }
                TournamentPlayer::where('tournament_id', $tournament->id)
                    ->where('user_id', $uid)
                    ->update(['status' => 'eliminated', 'eliminated_round' => $tournament->current_round]);
            }

            $roundDone = ! TournamentTable::where('tournament_id', $tournament->id)
                ->where('round', $tournament->current_round)
                ->where('status', '!=', 'finished')
                ->exists();

            return ['tournamentId' => $tournament->id, 'advance' => $roundDone];
        });

        if ($decision === null) {
            return;
        }

        $tournament = Tournament::find($decision['tournamentId']);
        if (! $tournament) {
            return;
        }

        $this->broadcastUpdate($tournament);

        if ($decision['advance']) {
            $this->advanceRound($tournament);
        }
    }

    /**
     * After a round completes: crown the champion if one player remains,
     * otherwise seat the next round with the survivors.
     */
    private function advanceRound(Tournament $tournament): void
    {
        $active = $tournament->players()
            ->where('status', 'active')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (count($active) <= 1) {
            $this->finish($tournament, $active[0] ?? null);

            return;
        }

        $this->seatRound($tournament, $tournament->current_round + 1, $active);
    }

    /**
     * Partition the given players into tables and spin up an engine match for
     * each. A leftover single player gets a bye (auto-advances). Broadcasts the
     * per-round match-ready signal so clients jump into their table.
     *
     * @param  array<int, int>  $userIds
     */
    private function seatRound(Tournament $tournament, int $round, array $userIds): void
    {
        $tournament->update(['current_round' => $round]);

        shuffle($userIds);
        $groups = $this->partition($userIds, $tournament->table_size);
        $names = User::whereIn('id', $userIds)->pluck('name', 'id');

        $tablesPayload = [];
        $byes = [];

        foreach ($groups as $group) {
            $group = array_map('intval', $group);

            if (count($group) < 2) {
                // Bye: this player can't form a table this round and advances.
                $byes[] = $group[0];

                continue;
            }

            $players = array_map(
                fn ($uid) => ['userId' => $uid, 'name' => (string) ($names[$uid] ?? 'Player')],
                $group,
            );

            $res = $this->engine->createTournamentMatch($players, $tournament->settings);
            $newMatchId = (string) $res['matchId'];
            $roster = $res['roster'] ?? [];
            $states = $res['states'] ?? [];

            // Mirror the match so channel auth + state fetch work for it.
            GameMatch::updateOrCreate(
                ['id' => $newMatchId],
                [
                    'status' => 'playing',
                    'host_user_id' => $group[0],
                    'settings' => $tournament->settings,
                    'started_at' => now(),
                ],
            );
            $this->mirrorRoster($newMatchId, $roster);

            TournamentTable::create([
                'tournament_id' => $tournament->id,
                'round' => $round,
                'match_id' => $newMatchId,
                'status' => 'running',
                'player_user_ids' => $group,
            ]);

            foreach ($states as $state) {
                $this->safeBroadcast(new GameStateUpdated($newMatchId, $state));
            }

            $tablesPayload[] = [
                'matchId' => $newMatchId,
                'userIds' => $group,
                'roster' => $roster,
            ];
        }

        $this->glog('round seated', [
            'tournament' => $tournament->id,
            'round' => $round,
            'tables' => count($tablesPayload),
            'byes' => count($byes),
        ]);

        $this->safeBroadcast(new TournamentMatchReady($tournament->id, $round, $tablesPayload, $byes));
        $this->broadcastUpdate($tournament->fresh());
    }

    /**
     * Finish the tournament: record the champion, pay the prize pool (0 in the
     * free phase), and broadcast final standings.
     */
    private function finish(Tournament $tournament, ?int $champUserId): void
    {
        $prize = (int) $tournament->prize_pool;

        if ($champUserId) {
            TournamentPlayer::where('tournament_id', $tournament->id)
                ->where('user_id', $champUserId)
                ->update(['status' => 'champion', 'place' => 1]);

            $champ = User::find($champUserId);
            if ($champ) {
                if ($prize > 0) {
                    $champ->increment('coins', $prize);
                }
                $champ->increment('wins');
            }
        }

        $tournament->update(['status' => 'finished', 'winner_user_id' => $champUserId]);

        $champName = $champUserId
            ? (string) (User::whereKey($champUserId)->value('name') ?? 'Champion')
            : 'No one';

        $this->glog('tournament finished', [
            'tournament' => $tournament->id,
            'champion' => $champUserId,
            'prize' => $prize,
        ]);

        $this->safeBroadcast(new TournamentFinished(
            $tournament->id,
            $champUserId,
            $champName,
            $prize,
            $this->standings($tournament->fresh()),
        ));
        $this->broadcastUpdate($tournament->fresh());
    }

    /**
     * Even partition of players into ceil(n/maxSize) tables, sizes differing by
     * at most one. For n >= 2 this only ever yields a single-player group (a bye)
     * with odd counts and small table sizes (e.g. heads-up).
     *
     * @param  array<int, int>  $userIds
     * @return array<int, array<int, int>>
     */
    private function partition(array $userIds, int $maxSize): array
    {
        $n = count($userIds);
        if ($n === 0) {
            return [];
        }
        $maxSize = max(2, $maxSize);
        $tables = max(1, (int) ceil($n / $maxSize));
        $base = intdiv($n, $tables);
        $extra = $n % $tables;

        $groups = [];
        $idx = 0;
        for ($i = 0; $i < $tables; $i++) {
            $size = $base + ($i < $extra ? 1 : 0);
            $groups[] = array_slice($userIds, $idx, $size);
            $idx += $size;
        }

        return $groups;
    }

    /**
     * Public-facing snapshot used by both the broadcast and the show endpoint.
     *
     * @return array<string, mixed>
     */
    public function summary(Tournament $tournament): array
    {
        $tournament->loadMissing('players.user');

        return [
            'id' => $tournament->id,
            'code' => $tournament->code,
            'format' => $tournament->format,
            'status' => $tournament->status,
            'hostUserId' => (int) $tournament->host_user_id,
            'buyIn' => (int) $tournament->buy_in,
            'prizePool' => (int) $tournament->prize_pool,
            'tableSize' => (int) $tournament->table_size,
            'currentRound' => (int) $tournament->current_round,
            'roundsTotal' => $tournament->rounds_total,
            'winnerUserId' => $tournament->winner_user_id ? (int) $tournament->winner_user_id : null,
            'players' => $tournament->players
                ->map(fn (TournamentPlayer $p) => [
                    'userId' => (int) $p->user_id,
                    'name' => $p->user?->name ?? 'Player',
                    'status' => $p->status,
                    'points' => (int) $p->points,
                    'place' => $p->place,
                    'eliminatedRound' => $p->eliminated_round,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * The running table (if any) that the given user is seated at in the current
     * round — so a client can (re)join its match on open/reconnect.
     */
    public function currentMatchIdFor(Tournament $tournament, int $userId): ?string
    {
        $tables = TournamentTable::where('tournament_id', $tournament->id)
            ->where('round', $tournament->current_round)
            ->where('status', 'running')
            ->get(['match_id', 'player_user_ids']);

        foreach ($tables as $table) {
            if (in_array($userId, array_map('intval', $table->player_user_ids ?? []), true)) {
                return $table->match_id;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function standings(Tournament $tournament): array
    {
        $tournament->loadMissing('players.user');

        return $tournament->players
            ->map(fn (TournamentPlayer $p) => [
                'userId' => (int) $p->user_id,
                'name' => $p->user?->name ?? 'Player',
                'status' => $p->status,
                'points' => (int) $p->points,
                'place' => $p->place,
                'eliminatedRound' => $p->eliminated_round,
            ])
            ->sortByDesc(fn ($r) => $r['status'] === 'champion' ? PHP_INT_MAX : ($r['eliminatedRound'] ?? 0))
            ->values()
            ->all();
    }

    /**
     * Broadcast the current snapshot to the tournament channel. Public so the
     * controller can announce join/leave changes during registration.
     */
    public function broadcast(Tournament $tournament): void
    {
        $this->broadcastUpdate($tournament);
    }

    private function broadcastUpdate(Tournament $tournament): void
    {
        $this->safeBroadcast(new TournamentUpdate($tournament->id, $this->summary($tournament)));
    }

    /**
     * Mirror the engine roster into match_players for channel auth.
     *
     * @param  array<int, array<string, mixed>>  $roster
     */
    private function mirrorRoster(string $matchId, array $roster): void
    {
        foreach ($roster as $entry) {
            $isAi = (bool) ($entry['isAI'] ?? false);
            if ($isAi || empty($entry['userId'])) {
                continue;
            }
            MatchPlayer::updateOrCreate(
                ['match_id' => $matchId, 'user_id' => (int) $entry['userId']],
                ['seat_index' => $entry['seatIndex'] ?? null, 'is_ai' => false],
            );
        }
    }

    private function safeBroadcast(object $event): void
    {
        try {
            broadcast($event);
        } catch (\Throwable $e) {
            Log::channel('game')->warning('tournament broadcast failed', [
                'event' => class_basename($event),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function glog(string $msg, array $ctx = []): void
    {
        Log::channel('game')->info($msg, $ctx);
    }
}
