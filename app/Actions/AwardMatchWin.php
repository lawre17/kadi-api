<?php

namespace App\Actions;

use App\Models\CoinTransaction;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AwardMatchWin
{
    public const WIN_REWARD = 100;

    /**
     * Idempotently finish a match and credit the winner.
     *
     * Returns ['match_id', 'already_awarded' => bool, 'winner_user_id', 'coins'].
     * Safe to call multiple times for the same match: a finished match is a
     * no-op (status check) with a unique (match_id, reason) ledger backstop.
     *
     * @param  array<int, int>  $participantUserIds  human participant user ids
     * @param  array<string, mixed>|null  $settings
     * @return array<string, mixed>
     */
    public function handle(string $matchId, int $winnerUserId, array $participantUserIds, ?array $settings = null): array
    {
        $participants = collect($participantUserIds)
            ->map(fn ($id) => (int) $id)
            ->push($winnerUserId)
            ->unique()
            ->values();

        return DB::transaction(function () use ($matchId, $winnerUserId, $participants, $settings) {
            // Lock the existing match row (if any) so concurrent awards serialize.
            $match = GameMatch::query()->lockForUpdate()->find($matchId);

            // Idempotent: a finished match has already been awarded.
            if ($match && $match->status === 'finished') {
                return [
                    'match_id' => $match->id,
                    'already_awarded' => true,
                    'winner_user_id' => (int) $match->winner_user_id,
                    'coins' => (int) User::whereKey($match->winner_user_id)->value('coins'),
                ];
            }

            $now = now();

            if ($match) {
                $match->fill([
                    'status' => 'finished',
                    'winner_user_id' => $winnerUserId,
                    'settings' => $settings ?? $match->settings,
                    'finished_at' => $now,
                ])->save();
            } else {
                $match = GameMatch::create([
                    'id' => $matchId,
                    'status' => 'finished',
                    'winner_user_id' => $winnerUserId,
                    'settings' => $settings,
                    'finished_at' => $now,
                ]);
            }

            // Record each human participant as a player with a result.
            foreach ($participants as $userId) {
                MatchPlayer::updateOrCreate(
                    ['match_id' => $matchId, 'user_id' => $userId],
                    ['result' => $userId === $winnerUserId ? 'won' : 'lost']
                );
            }

            // Ledger entry — unique (match_id, reason) is a backstop against
            // double-award even if the status check above were bypassed.
            CoinTransaction::create([
                'user_id' => $winnerUserId,
                'match_id' => $matchId,
                'amount' => self::WIN_REWARD,
                'reason' => 'win',
            ]);

            // Winner: +coins, +1 win.
            User::whereKey($winnerUserId)->update([
                'coins' => DB::raw('coins + '.self::WIN_REWARD),
                'wins' => DB::raw('wins + 1'),
            ]);

            // Losers: +1 loss each.
            $loserIds = $participants->reject(fn ($id) => $id === $winnerUserId)->values();
            if ($loserIds->isNotEmpty()) {
                User::whereIn('id', $loserIds)->update([
                    'losses' => DB::raw('losses + 1'),
                ]);
            }

            return [
                'match_id' => $matchId,
                'already_awarded' => false,
                'winner_user_id' => $winnerUserId,
                'coins' => (int) User::whereKey($winnerUserId)->value('coins'),
            ];
        });
    }
}
