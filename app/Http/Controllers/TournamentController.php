<?php

namespace App\Http\Controllers;

use App\Actions\TournamentCoins;
use App\Models\Tournament;
use App\Models\TournamentPlayer;
use App\Services\TournamentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TournamentController extends Controller
{
    public function __construct(
        private readonly TournamentService $service,
        private readonly TournamentCoins $coins,
    ) {}

    /**
     * Create a tournament. The creator becomes the host and first entrant, and
     * pays the buy-in (escrowed into the prize pool).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'format' => ['nullable', 'in:bracket,league,survival'],
            'tableSize' => ['nullable', 'integer', 'min:2', 'max:6'],
            'buyIn' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'roundsTotal' => ['nullable', 'integer', 'min:2', 'max:10'],
            'settings' => ['nullable', 'array'],
        ]);

        $user = $request->user();
        $format = $validated['format'] ?? 'bracket';

        // Create + seat host + charge atomically so an unaffordable buy-in rolls
        // the whole thing back (no orphaned tournament).
        $tournament = DB::transaction(function () use ($validated, $user, $format) {
            $t = Tournament::create([
                'code' => $this->uniqueCode(),
                'format' => $format,
                'status' => 'registering',
                'host_user_id' => $user->id,
                'buy_in' => $validated['buyIn'] ?? 0,
                'prize_pool' => 0,
                'table_size' => $validated['tableSize'] ?? 4,
                // League plays a fixed number of rounds; others stop at a champion.
                'rounds_total' => $format === 'league' ? ($validated['roundsTotal'] ?? 3) : null,
                // Online tournaments are always unassisted (no card hints).
                'settings' => array_merge(
                    (array) ($validated['settings'] ?? []),
                    ['assistedMode' => false],
                ),
            ]);

            TournamentPlayer::create([
                'tournament_id' => $t->id,
                'user_id' => $user->id,
                'status' => 'registered',
            ]);

            $this->coins->charge($user->id, $t);

            return $t;
        });

        $summary = $this->service->summary($tournament->fresh());
        $this->service->broadcast($tournament->fresh());

        return response()->json($summary, 201);
    }

    /**
     * Join a registering tournament by code.
     */
    public function join(Request $request, string $code): JsonResponse
    {
        $user = $request->user();

        $tournament = Tournament::where('code', strtoupper($code))->first();
        if (! $tournament) {
            return response()->json(['message' => 'Tournament not found.'], 404);
        }
        if ($tournament->status !== 'registering') {
            return response()->json(['message' => 'This tournament has already started.'], 409);
        }

        // Charge only on a genuinely new entry, atomically with seating so a
        // failed charge (insufficient coins → 422) doesn't seat an unpaid player.
        DB::transaction(function () use ($tournament, $user) {
            $player = TournamentPlayer::firstOrCreate(
                ['tournament_id' => $tournament->id, 'user_id' => $user->id],
                ['status' => 'registered'],
            );
            if ($player->wasRecentlyCreated) {
                $this->coins->charge($user->id, $tournament);
            }
        });

        $this->service->broadcast($tournament->fresh());

        return response()->json($this->service->summary($tournament->fresh()));
    }

    /**
     * Leave a tournament that hasn't started yet. The host leaving cancels the
     * whole thing and refunds everyone; anyone else just gets their own buy-in
     * back. (Leaving once it's running is a forfeit and isn't refunded.)
     */
    public function leave(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $tournament = Tournament::find($id);
        if (! $tournament) {
            return response()->json(['message' => 'Tournament not found.'], 404);
        }

        if ($tournament->status === 'registering') {
            $isHost = (int) $tournament->host_user_id === (int) $user->id;

            if ($isHost) {
                // Cancel: refund all, then mark finished with no champion.
                $this->coins->refundAll($tournament);
                $tournament->update(['status' => 'finished', 'winner_user_id' => null]);
            } else {
                DB::transaction(function () use ($tournament, $user) {
                    $deleted = TournamentPlayer::where('tournament_id', $tournament->id)
                        ->where('user_id', $user->id)
                        ->delete();
                    if ($deleted) {
                        $this->coins->refund((int) $user->id, $tournament);
                    }
                });
            }

            $this->service->broadcast($tournament->fresh());
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Host starts the tournament; round 1 is seated immediately.
     */
    public function start(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $tournament = Tournament::find($id);
        if (! $tournament) {
            return response()->json(['message' => 'Tournament not found.'], 404);
        }
        if ((int) $tournament->host_user_id !== (int) $user->id) {
            return response()->json(['message' => 'Only the host can start the tournament.'], 403);
        }
        if ($tournament->status !== 'registering') {
            return response()->json(['message' => 'Already started.'], 409);
        }
        if ($tournament->players()->count() < 2) {
            return response()->json(['message' => 'Need at least 2 players to start.'], 409);
        }

        $this->service->start($tournament);

        return response()->json($this->service->summary($tournament->fresh()));
    }

    /**
     * Current tournament snapshot, plus the caller's live table match (if any) so
     * a client can render and (re)join on open/reconnect.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $tournament = Tournament::find($id);
        if (! $tournament) {
            return response()->json(['message' => 'Tournament not found.'], 404);
        }

        $isEntrant = TournamentPlayer::where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->exists();
        if (! $isEntrant) {
            return response()->json(['message' => 'You are not in this tournament.'], 403);
        }

        return response()->json([
            'tournament' => $this->service->summary($tournament),
            'myMatchId' => $this->service->currentMatchIdFor($tournament, (int) $user->id),
            'tables' => $this->service->bracket($tournament),
        ]);
    }

    private function uniqueCode(): string
    {
        do {
            // Drop ambiguous characters; 5 chars from a 30-symbol alphabet.
            $code = substr(str_replace(
                ['I', 'O', '0', '1', 'L'],
                ['A', 'B', '2', '3', 'M'],
                strtoupper(Str::random(5)),
            ), 0, 5);
        } while (Tournament::where('code', $code)->exists());

        return $code;
    }
}
