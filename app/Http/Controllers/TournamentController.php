<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Models\TournamentPlayer;
use App\Services\TournamentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TournamentController extends Controller
{
    public function __construct(private readonly TournamentService $service) {}

    /**
     * Create a tournament. The creator becomes the host and first entrant.
     * Phase 1 is free to enter; coin buy-ins land in a later phase, so buy_in is
     * forced to 0 here regardless of the request.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'format' => ['nullable', 'in:bracket,league,survival'],
            'tableSize' => ['nullable', 'integer', 'min:2', 'max:6'],
            'settings' => ['nullable', 'array'],
        ]);

        $user = $request->user();

        $tournament = Tournament::create([
            'code' => $this->uniqueCode(),
            'format' => $validated['format'] ?? 'bracket',
            'status' => 'registering',
            'host_user_id' => $user->id,
            'buy_in' => 0,
            'prize_pool' => 0,
            'table_size' => $validated['tableSize'] ?? 4,
            // Online tournaments are always unassisted (no card hints).
            'settings' => array_merge(
                (array) ($validated['settings'] ?? []),
                ['assistedMode' => false],
            ),
        ]);

        TournamentPlayer::create([
            'tournament_id' => $tournament->id,
            'user_id' => $user->id,
            'status' => 'registered',
        ]);

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

        TournamentPlayer::firstOrCreate(
            ['tournament_id' => $tournament->id, 'user_id' => $user->id],
            ['status' => 'registered'],
        );

        $this->service->broadcast($tournament->fresh());

        return response()->json($this->service->summary($tournament->fresh()));
    }

    /**
     * Leave a tournament that hasn't started yet.
     */
    public function leave(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $tournament = Tournament::find($id);
        if (! $tournament) {
            return response()->json(['message' => 'Tournament not found.'], 404);
        }
        if ($tournament->status === 'registering') {
            TournamentPlayer::where('tournament_id', $tournament->id)
                ->where('user_id', $user->id)
                ->delete();
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
