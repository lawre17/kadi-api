<?php

use App\Actions\AwardMatchWin;
use App\Events\GameStateUpdated;
use App\Events\MatchAwarded;
use App\Events\RoomStateUpdated;
use App\Models\CoinTransaction;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

function nodeUrl(string $path): string
{
    return rtrim((string) config('services.node.url'), '/').$path;
}

test('creating a match relays to the engine and persists match + host player', function () {
    $host = User::factory()->create();
    Sanctum::actingAs($host);
    Event::fake();

    Http::fake([
        nodeUrl('/rooms') => Http::response([
            'code' => 'ABCDE',
            'matchId' => 'match-1',
            'roster' => [
                ['userId' => (string) $host->id, 'name' => $host->name, 'seatIndex' => 0, 'engineId' => 'e0', 'isAI' => false],
            ],
        ]),
    ]);

    $response = $this->postJson('/api/matches', ['settings' => ['deck' => 'standard']]);

    $response->assertCreated()
        ->assertJsonPath('code', 'ABCDE')
        ->assertJsonPath('matchId', 'match-1');

    $this->assertDatabaseHas('matches', [
        'id' => 'match-1',
        'status' => 'lobby',
        'host_user_id' => $host->id,
    ]);
    $this->assertDatabaseHas('match_players', [
        'match_id' => 'match-1',
        'user_id' => $host->id,
        'seat_index' => 0,
    ]);

    Event::assertDispatched(RoomStateUpdated::class);

    // The engine received the host id + secret header.
    Http::assertSent(function ($request) use ($host) {
        return $request->url() === nodeUrl('/rooms')
            && $request->hasHeader('X-Internal-Secret', config('services.node.secret'))
            && $request['hostUserId'] === $host->id;
    });
});

test('joining a match persists the joining player', function () {
    $host = User::factory()->create();
    $joiner = User::factory()->create();

    GameMatch::create(['id' => 'match-2', 'status' => 'lobby', 'host_user_id' => $host->id]);
    MatchPlayer::create(['match_id' => 'match-2', 'user_id' => $host->id, 'seat_index' => 0]);

    Sanctum::actingAs($joiner);
    Event::fake();

    Http::fake([
        nodeUrl('/rooms/ABCDE/join') => Http::response([
            'matchId' => 'match-2',
            'roster' => [
                ['userId' => (string) $host->id, 'name' => $host->name, 'seatIndex' => 0, 'engineId' => 'e0', 'isAI' => false],
                ['userId' => (string) $joiner->id, 'name' => $joiner->name, 'seatIndex' => 1, 'engineId' => 'e1', 'isAI' => false],
            ],
        ]),
    ]);

    $this->postJson('/api/matches/ABCDE/join')
        ->assertOk()
        ->assertJsonPath('matchId', 'match-2');

    $this->assertDatabaseHas('match_players', [
        'match_id' => 'match-2',
        'user_id' => $joiner->id,
        'seat_index' => 1,
    ]);

    Event::assertDispatched(RoomStateUpdated::class);
});

test('a move relays to the engine and credits the winner +100 on finish', function () {
    $winner = User::factory()->create(['coins' => 0, 'wins' => 0]);
    $loser = User::factory()->create(['coins' => 0, 'losses' => 0]);

    GameMatch::create(['id' => 'match-3', 'status' => 'playing', 'host_user_id' => $winner->id]);
    MatchPlayer::create(['match_id' => 'match-3', 'user_id' => $winner->id, 'seat_index' => 0]);
    MatchPlayer::create(['match_id' => 'match-3', 'user_id' => $loser->id, 'seat_index' => 1]);

    Sanctum::actingAs($winner);
    Event::fake();

    Http::fake([
        nodeUrl('/matches/match-3/move') => Http::response([
            'states' => [['turn' => 1], ['turn' => 2]],
            'finished' => true,
            'winnerUserId' => (string) $winner->id,
        ]),
    ]);

    $this->postJson('/api/matches/match-3/move', ['move' => ['type' => 'play', 'card' => 'AS']])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('finished', true)
        ->assertJsonPath('winnerUserId', $winner->id);

    expect($winner->fresh()->coins)->toBe(100)
        ->and($winner->fresh()->wins)->toBe(1)
        ->and($loser->fresh()->losses)->toBe(1);

    $this->assertDatabaseHas('matches', ['id' => 'match-3', 'status' => 'finished', 'winner_user_id' => $winner->id]);
    $this->assertDatabaseHas('coin_transactions', ['match_id' => 'match-3', 'user_id' => $winner->id, 'amount' => 100, 'reason' => 'win']);

    // One gameState event per state, plus one awarded event.
    Event::assertDispatchedTimes(GameStateUpdated::class, 2);
    Event::assertDispatched(MatchAwarded::class, fn (MatchAwarded $e) => $e->winnerUserId === $winner->id && $e->coins === 100);
});

test('awarding the same finished match twice is idempotent', function () {
    $winner = User::factory()->create(['coins' => 0, 'wins' => 0]);
    $loser = User::factory()->create(['coins' => 0, 'losses' => 0]);

    GameMatch::create(['id' => 'match-4', 'status' => 'playing', 'host_user_id' => $winner->id]);
    MatchPlayer::create(['match_id' => 'match-4', 'user_id' => $winner->id, 'seat_index' => 0]);
    MatchPlayer::create(['match_id' => 'match-4', 'user_id' => $loser->id, 'seat_index' => 1]);

    $action = app(AwardMatchWin::class);

    $first = $action->handle('match-4', $winner->id, [$winner->id, $loser->id]);
    expect($first['already_awarded'])->toBeFalse()->and($first['coins'])->toBe(100);

    $second = $action->handle('match-4', $winner->id, [$winner->id, $loser->id]);
    expect($second['already_awarded'])->toBeTrue();

    // Balances only moved once; exactly one ledger entry.
    expect($winner->fresh()->coins)->toBe(100)
        ->and($winner->fresh()->wins)->toBe(1)
        ->and($loser->fresh()->losses)->toBe(1)
        ->and(CoinTransaction::where('match_id', 'match-4')->count())->toBe(1);
});

test('a non-participant cannot submit a move', function () {
    $participant = User::factory()->create();
    $stranger = User::factory()->create();

    GameMatch::create(['id' => 'match-5', 'status' => 'playing', 'host_user_id' => $participant->id]);
    MatchPlayer::create(['match_id' => 'match-5', 'user_id' => $participant->id, 'seat_index' => 0]);

    Sanctum::actingAs($stranger);
    Http::fake();

    $this->postJson('/api/matches/match-5/move', ['move' => ['type' => 'pass']])
        ->assertForbidden();

    // The engine must never have been called.
    Http::assertNothingSent();
});

/**
 * Switch the test broadcaster to reverb (pusher-compatible) so the channel
 * callback in routes/channels.php actually runs — the default 'null' driver
 * no-ops authorization. Channels were bound to the null connection at boot, so
 * re-load them onto the fresh reverb connection.
 */
function useReverbBroadcaster(): void
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    Broadcast::connection();
    require base_path('routes/channels.php');
}

test('the match channel authorizes a participant', function () {
    useReverbBroadcaster();

    $participant = User::factory()->create();
    GameMatch::create(['id' => 'match-6', 'status' => 'playing', 'host_user_id' => $participant->id]);
    MatchPlayer::create(['match_id' => 'match-6', 'user_id' => $participant->id, 'seat_index' => 0]);

    $token = $participant->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/broadcasting/auth', [
            'channel_name' => 'private-match.match-6',
            'socket_id' => '123.456',
        ])->assertOk();
});

test('the match channel rejects a non-participant', function () {
    useReverbBroadcaster();

    $participant = User::factory()->create();
    $stranger = User::factory()->create();
    GameMatch::create(['id' => 'match-7', 'status' => 'playing', 'host_user_id' => $participant->id]);
    MatchPlayer::create(['match_id' => 'match-7', 'user_id' => $participant->id, 'seat_index' => 0]);

    $token = $stranger->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/broadcasting/auth', [
            'channel_name' => 'private-match.match-7',
            'socket_id' => '123.456',
        ])->assertForbidden();
});

test('the broadcasting auth route exists', function () {
    $routes = collect(app('router')->getRoutes())->map(fn ($r) => $r->uri());

    expect($routes)->toContain('broadcasting/auth');
});
