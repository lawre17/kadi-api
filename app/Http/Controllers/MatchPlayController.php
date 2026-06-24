<?php

namespace App\Http\Controllers;

use App\Actions\AwardMatchWin;
use App\Events\GameStateUpdated;
use App\Events\MatchAwarded;
use App\Events\RoomStateUpdated;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Services\NodeEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MatchPlayController extends Controller
{
    public function __construct(private readonly NodeEngine $engine) {}

    /**
     * Create a room. The Node engine is authoritative for game ids; we mirror
     * a 'lobby' match row + the host's player row for channel auth / awards.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'settings' => ['nullable', 'array'],
            'aiOpponents' => ['nullable', 'integer', 'min:0', 'max:7'],
        ]);

        $user = $request->user();

        $result = $this->engine->createRoom(
            $user->id,
            $user->name,
            $validated['settings'] ?? null,
            $validated['aiOpponents'] ?? null,
        );

        $matchId = $result['matchId'];
        $roster = $result['roster'] ?? [];

        GameMatch::updateOrCreate(
            ['id' => $matchId],
            [
                'status' => 'lobby',
                'host_user_id' => $user->id,
                'settings' => $validated['settings'] ?? null,
            ],
        );

        $this->syncRoster($matchId, $roster);

        broadcast(new RoomStateUpdated($matchId, $roster));

        return response()->json([
            'code' => $result['code'],
            'matchId' => $matchId,
            'roster' => $roster,
        ], 201);
    }

    /**
     * Join an existing room by code.
     */
    public function join(Request $request, string $code): JsonResponse
    {
        $user = $request->user();

        $result = $this->engine->joinRoom($code, $user->id, $user->name);

        $matchId = $result['matchId'];
        $roster = $result['roster'] ?? [];

        $this->syncRoster($matchId, $roster);

        broadcast(new RoomStateUpdated($matchId, $roster));

        return response()->json([
            'matchId' => $matchId,
            'roster' => $roster,
        ]);
    }

    /**
     * Start the game. Only meaningful for the host; the engine enforces that.
     */
    public function start(Request $request, string $matchId): JsonResponse
    {
        $user = $request->user();

        $result = $this->engine->startGame($matchId, $user->id);

        $roster = $result['roster'] ?? [];
        $states = $result['states'] ?? [];

        $this->syncRoster($matchId, $roster);

        GameMatch::where('id', $matchId)->update([
            'status' => 'playing',
            'started_at' => now(),
        ]);

        // Broadcast the initial state to the room.
        if (! empty($states)) {
            broadcast(new GameStateUpdated($matchId, $states[0]));
        }

        return response()->json([
            'matchId' => $matchId,
            'roster' => $roster,
        ]);
    }

    /**
     * Relay a move to the engine and broadcast the resulting state(s).
     */
    public function move(Request $request, string $matchId, AwardMatchWin $awardMatchWin): JsonResponse
    {
        $validated = $request->validate([
            'move' => ['required', 'array'],
        ]);

        $user = $request->user();

        // Must be a participant of this match.
        $isParticipant = MatchPlayer::where('match_id', $matchId)
            ->where('user_id', $user->id)
            ->exists();

        if (! $isParticipant) {
            return response()->json(['message' => 'You are not a participant of this match.'], 403);
        }

        $result = $this->engine->move($matchId, $user->id, $validated['move']);

        $states = $result['states'] ?? [];
        $finished = (bool) ($result['finished'] ?? false);
        $winnerUserId = $result['winnerUserId'] ?? null;

        // Broadcast every intermediate/final state in order.
        foreach ($states as $state) {
            broadcast(new GameStateUpdated($matchId, $state));
        }

        if ($finished && $winnerUserId !== null) {
            $winnerId = (int) $winnerUserId;

            $participantIds = MatchPlayer::where('match_id', $matchId)
                ->where('is_ai', false)
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $settings = GameMatch::where('id', $matchId)->value('settings');

            $award = $awardMatchWin->handle($matchId, $winnerId, $participantIds, $settings);

            broadcast(new MatchAwarded($matchId, $award['winner_user_id'], $award['coins']));

            return response()->json([
                'ok' => true,
                'finished' => true,
                'winnerUserId' => $winnerId,
            ]);
        }

        return response()->json([
            'ok' => true,
            'finished' => $finished,
            'winnerUserId' => $winnerUserId,
        ]);
    }

    /**
     * Mirror the engine roster into match_players for channel auth and awards.
     *
     * @param  array<int, array<string, mixed>>  $roster
     */
    private function syncRoster(string $matchId, array $roster): void
    {
        foreach ($roster as $entry) {
            $isAi = (bool) ($entry['isAI'] ?? false);

            // AI players have no app user; skip persisting them.
            if ($isAi || empty($entry['userId'])) {
                continue;
            }

            MatchPlayer::updateOrCreate(
                ['match_id' => $matchId, 'user_id' => (int) $entry['userId']],
                [
                    'seat_index' => $entry['seatIndex'] ?? null,
                    'is_ai' => false,
                ],
            );
        }
    }
}
