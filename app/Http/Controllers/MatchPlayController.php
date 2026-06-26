<?php

namespace App\Http\Controllers;

use App\Actions\AwardMatchWin;
use App\Events\ChatMessage;
use App\Events\GameStateUpdated;
use App\Events\MatchAwarded;
use App\Events\RoomStateUpdated;
use App\Events\VoiceMessage;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Services\NodeEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MatchPlayController extends Controller
{
    public function __construct(private readonly NodeEngine $engine) {}

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function glog(string $msg, array $ctx = []): void
    {
        Log::channel('game')->info($msg, $ctx);
    }

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

        $this->glog('room created', [
            'matchId' => $matchId,
            'code' => $result['code'],
            'host' => $user->id,
            'name' => $user->name,
            'ai' => $validated['aiOpponents'] ?? 0,
        ]);

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

        $this->glog('player joined', [
            'matchId' => $matchId,
            'code' => $code,
            'user' => $user->id,
            'name' => $user->name,
            'players' => count($roster),
        ]);

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

        $this->glog('game started', [
            'matchId' => $matchId,
            'host' => $user->id,
            'players' => count($roster),
        ]);

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

        $moveType = $validated['move']['type'] ?? 'unknown';

        try {
            $result = $this->engine->move($matchId, $user->id, $validated['move']);
        } catch (\Throwable $e) {
            Log::channel('game')->warning('move rejected', [
                'matchId' => $matchId,
                'user' => $user->id,
                'move' => $moveType,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $states = $result['states'] ?? [];
        $finished = (bool) ($result['finished'] ?? false);
        $winnerUserId = $result['winnerUserId'] ?? null;

        $this->glog('move', [
            'matchId' => $matchId,
            'user' => $user->id,
            'move' => $moveType,
            'states' => count($states),
            'finished' => $finished,
            'winner' => $winnerUserId,
        ]);

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

            $this->glog('coins awarded', [
                'matchId' => $matchId,
                'winner' => $winnerId,
                'coins' => $award['coins'],
            ]);

            // Primary voice-clip cleanup: the game is over, so its clips are no
            // longer needed. Never let cleanup failure break the move response.
            try {
                Storage::disk('public')->deleteDirectory("voice/{$matchId}");
            } catch (\Throwable $e) {
                Log::channel('game')->warning('voice cleanup failed', [
                    'matchId' => $matchId,
                    'error' => $e->getMessage(),
                ]);
            }

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
     * Accept a short push-to-talk voice clip from a participant, store it on the
     * public disk, and broadcast its URL to the room so other players auto-play
     * it. Additive — does not touch gameplay/coins.
     */
    public function voice(Request $request, string $matchId): JsonResponse
    {
        $request->validate([
            'clip' => [
                'required',
                'file',
                'mimetypes:audio/mp4,audio/aac,audio/m4a,audio/mpeg,audio/x-m4a,application/octet-stream',
                'max:2048',
            ],
        ]);

        $user = $request->user();

        // Must be a participant of this match.
        $isParticipant = MatchPlayer::where('match_id', $matchId)
            ->where('user_id', $user->id)
            ->exists();

        if (! $isParticipant) {
            return response()->json(['message' => 'You are not a participant of this match.'], 403);
        }

        // Group clips by match so they can be deleted as a unit when the game
        // ends (see move()), and so the backstop prune can reason per-match.
        $path = $request->file('clip')->storePubliclyAs(
            "voice/{$matchId}",
            Str::uuid().'.m4a',
            'public',
        );

        // Force https — APP_URL is http on this host, but the site is https-only
        // and Android's media player won't follow an http->https redirect.
        $url = preg_replace('#^http://#', 'https://', Storage::disk('public')->url($path));

        broadcast(new VoiceMessage($matchId, (int) $user->id, $user->name, $url));

        $this->glog('voice', [
            'matchId' => $matchId,
            'user' => $user->id,
            'url' => $url,
        ]);

        return response()->json(['url' => $url]);
    }

    /**
     * Relay a short text chat message to the room. Ephemeral — broadcast only,
     * never stored (nothing to accumulate on the server). Additive: does not
     * touch gameplay or coins.
     */
    public function chat(Request $request, string $matchId): JsonResponse
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:200'],
        ]);

        $user = $request->user();

        // Must be a participant of this match.
        $isParticipant = MatchPlayer::where('match_id', $matchId)
            ->where('user_id', $user->id)
            ->exists();

        if (! $isParticipant) {
            return response()->json(['message' => 'You are not a participant of this match.'], 403);
        }

        // Collapse whitespace and hard-trim so a giant blob can't be forced past
        // the 200-char cap via newlines.
        $text = trim(preg_replace('/\s+/', ' ', $validated['text']) ?? '');

        if ($text === '') {
            return response()->json(['message' => 'Empty message.'], 422);
        }

        broadcast(new ChatMessage($matchId, (int) $user->id, $user->name, $text));

        $this->glog('chat', [
            'matchId' => $matchId,
            'user' => $user->id,
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Current authoritative state + roster for a match. Clients call this when
     * they open the game so they render immediately instead of waiting for the
     * next broadcast.
     */
    public function state(Request $request, string $matchId): JsonResponse
    {
        $user = $request->user();

        $isParticipant = MatchPlayer::where('match_id', $matchId)
            ->where('user_id', $user->id)
            ->exists();

        if (! $isParticipant) {
            return response()->json(['message' => 'You are not a participant of this match.'], 403);
        }

        $result = $this->engine->getState($matchId);

        return response()->json([
            'state' => $result['state'] ?? null,
            'roster' => $result['roster'] ?? [],
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
