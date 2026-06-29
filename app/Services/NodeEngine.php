<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Thin client for the private Node "engine" service that holds the
 * authoritative game logic. Every request carries the shared internal secret.
 *
 * 4xx responses from Node are translated into a Laravel ValidationException
 * (HTTP 422) carrying the engine's message, so the client sees a clean error.
 */
class NodeEngine
{
    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.node.url'), '/'))
            ->withHeaders(['X-Internal-Secret' => (string) config('services.node.secret')])
            ->acceptJson()
            ->asJson();
    }

    /**
     * @param  array<string, mixed>|null  $settings
     * @return array{code: string, matchId: string, roster: array<int, array<string, mixed>>}
     */
    public function createRoom(int $hostUserId, string $hostName, ?array $settings = null, ?int $aiOpponents = null): array
    {
        return $this->send($this->client()->post('/rooms', array_filter([
            'hostUserId' => $hostUserId,
            'hostName' => $hostName,
            'settings' => $settings,
            'aiOpponents' => $aiOpponents,
        ], fn ($v) => $v !== null)));
    }

    /**
     * @return array{matchId: string, roster: array<int, array<string, mixed>>}
     */
    public function joinRoom(string $code, int $userId, string $name): array
    {
        return $this->send($this->client()->post("/rooms/{$code}/join", [
            'userId' => $userId,
            'name' => $name,
        ]));
    }

    /**
     * @return array{matchId: string, states: array<int, mixed>, roster: array<int, array<string, mixed>>}
     */
    public function startGame(string $code, int $userId): array
    {
        return $this->send($this->client()->post("/rooms/{$code}/start", [
            'userId' => $userId,
        ]));
    }

    /**
     * @param  array<string, mixed>  $move
     * @return array{states: array<int, mixed>, finished: bool, winnerUserId: string|null}
     */
    public function move(string $matchId, int $userId, array $move): array
    {
        return $this->send($this->client()->post("/matches/{$matchId}/move", [
            'userId' => $userId,
            'move' => $move,
        ]));
    }

    /**
     * @return array{state: mixed, roster: array<int, array<string, mixed>>}
     */
    public function getState(string $matchId): array
    {
        return $this->send($this->client()->get("/matches/{$matchId}/state"));
    }

    /**
     * Seat a fixed human roster (no AI) as a tournament table and start it.
     *
     * @param  array<int, array{userId: int, name: string}>  $players
     * @param  array<string, mixed>|null  $settings
     * @return array{matchId: string, code: string, states: array<int, mixed>, roster: array<int, array<string, mixed>>}
     */
    public function createTournamentMatch(array $players, ?array $settings = null): array
    {
        return $this->send($this->client()->post('/tournament-matches', array_filter([
            'players' => $players,
            'settings' => $settings,
        ], fn ($v) => $v !== null)));
    }

    /**
     * @return array{states: array<int, mixed>, finished: bool, winnerUserId: string|null}
     */
    public function leave(string $matchId, int $userId): array
    {
        return $this->send($this->client()->post("/matches/{$matchId}/leave", [
            'userId' => $userId,
        ]));
    }

    /**
     * @return array{skipped: bool, states: array<int, mixed>, finished: bool, winnerUserId: string|null}
     */
    public function timeout(string $matchId, int $userId): array
    {
        return $this->send($this->client()->post("/matches/{$matchId}/timeout", [
            'userId' => $userId,
        ]));
    }

    /**
     * @return array{started: bool, ready: array<int, string>, total: int, newMatchId: string|null, code: string|null, hostUserId: string|null, roster: array<int, array<string, mixed>>, states: array<int, mixed>, cannot: bool}
     */
    public function rematch(string $matchId, int $userId): array
    {
        return $this->send($this->client()->post("/matches/{$matchId}/rematch", [
            'userId' => $userId,
        ]));
    }

    /**
     * Translate Node 4xx into a 422 ValidationException; throw on 5xx.
     *
     * @return array<string, mixed>
     */
    private function send(Response $response): array
    {
        if ($response->clientError()) {
            $message = $response->json('message')
                ?? $response->json('error')
                ?? 'The game engine rejected the request.';

            throw ValidationException::withMessages(['move' => [$message]]);
        }

        $response->throw();

        return (array) $response->json();
    }
}
