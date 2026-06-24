<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

test('a user can register and receives a token', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'password' => 'password123',
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'coins', 'wins', 'losses']])
        ->assertJsonPath('user.email', 'alice@example.com')
        ->assertJsonPath('user.coins', 0);

    $this->assertDatabaseHas('users', ['email' => 'alice@example.com']);
});

test('registration validates a unique email and password length', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->postJson('/api/register', [
        'name' => 'Bob',
        'email' => 'taken@example.com',
        'password' => 'short',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'password']);
});

test('a user can log in with valid credentials', function () {
    User::factory()->create([
        'email' => 'carol@example.com',
        'password' => 'password123',
    ]);

    $this->postJson('/api/login', [
        'email' => 'carol@example.com',
        'password' => 'password123',
    ])->assertOk()
        ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'coins', 'wins', 'losses']]);
});

test('login returns 422 on bad credentials', function () {
    User::factory()->create([
        'email' => 'dave@example.com',
        'password' => 'password123',
    ]);

    $this->postJson('/api/login', [
        'email' => 'dave@example.com',
        'password' => 'wrong-password',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

test('me returns the exact user shape for a bearer token', function () {
    $user = User::factory()->create(['coins' => 250, 'wins' => 3, 'losses' => 1]);
    $token = $user->createToken('mobile')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/me')
        ->assertOk()
        ->assertExactJson([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'coins' => 250,
                'wins' => 3,
                'losses' => 1,
            ],
        ]);
});

test('me is rejected without a token', function () {
    $this->getJson('/api/me')->assertUnauthorized();
});

test('logout revokes the current token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('mobile')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/logout')
        ->assertNoContent();

    // The token row is gone, so it can no longer authenticate future requests.
    expect($user->tokens()->count())->toBe(0);
});

test('an authenticated user can create a match room', function () {
    $user = User::factory()->create();
    $token = $user->createToken('mobile')->plainTextToken;

    // The room lifecycle now lives in the Node engine; we mock its response.
    Http::fake([
        rtrim((string) config('services.node.url'), '/').'/rooms' => Http::response([
            'code' => 'WXYZ1',
            'matchId' => 'node-match',
            'roster' => [
                ['userId' => (string) $user->id, 'name' => $user->name, 'seatIndex' => 0, 'engineId' => 'e0', 'isAI' => false],
            ],
        ]),
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/matches', ['settings' => ['max_players' => 4]])
        ->assertCreated()
        ->assertJsonStructure(['code', 'matchId', 'roster']);

    expect($response->json('code'))->toMatch('/^[A-Z0-9]{5}$/');

    $this->assertDatabaseHas('matches', [
        'id' => $response->json('matchId'),
        'status' => 'lobby',
        'host_user_id' => $user->id,
    ]);
});
