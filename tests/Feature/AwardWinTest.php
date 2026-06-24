<?php

use App\Models\CoinTransaction;
use App\Models\User;

function awardPayload(string $matchId, User $winner, array $losers, array $extra = []): array
{
    return array_merge([
        'match_id' => $matchId,
        'winner_user_id' => $winner->id,
        'participants' => collect([$winner, ...$losers])
            ->map(fn (User $u) => ['user_id' => $u->id])
            ->all(),
    ], $extra);
}

function awardHeaders(): array
{
    return ['X-Internal-Secret' => config('services.internal.secret')];
}

test('award-win requires a valid internal secret', function () {
    $winner = User::factory()->create();
    $loser = User::factory()->create();

    // Missing header.
    $this->postJson('/internal/award-win', awardPayload('m-1', $winner, [$loser]))
        ->assertForbidden();

    // Wrong header.
    $this->withHeaders(['X-Internal-Secret' => 'nope'])
        ->postJson('/internal/award-win', awardPayload('m-1', $winner, [$loser]))
        ->assertForbidden();
});

test('award-win awards coins, wins, and losses', function () {
    $winner = User::factory()->create(['coins' => 0, 'wins' => 0]);
    $loser = User::factory()->create(['coins' => 0, 'losses' => 0]);
    $matchId = (string) Str::uuid();

    $this->withHeaders(awardHeaders())
        ->postJson('/internal/award-win', awardPayload($matchId, $winner, [$loser], [
            'settings' => ['deck' => 'standard'],
        ]))
        ->assertOk()
        ->assertJsonPath('match_id', $matchId)
        ->assertJsonPath('winner.user_id', $winner->id)
        ->assertJsonPath('winner.coins', 100);

    expect($winner->fresh()->coins)->toBe(100)
        ->and($winner->fresh()->wins)->toBe(1)
        ->and($loser->fresh()->losses)->toBe(1)
        ->and($loser->fresh()->coins)->toBe(0);

    $this->assertDatabaseHas('matches', [
        'id' => $matchId,
        'status' => 'finished',
        'winner_user_id' => $winner->id,
    ]);
    $this->assertDatabaseHas('match_players', ['match_id' => $matchId, 'user_id' => $winner->id, 'result' => 'won']);
    $this->assertDatabaseHas('match_players', ['match_id' => $matchId, 'user_id' => $loser->id, 'result' => 'lost']);
    $this->assertDatabaseHas('coin_transactions', ['match_id' => $matchId, 'user_id' => $winner->id, 'amount' => 100, 'reason' => 'win']);
});

test('award-win is idempotent on match_id', function () {
    $winner = User::factory()->create(['coins' => 0, 'wins' => 0]);
    $loser = User::factory()->create(['losses' => 0]);
    $matchId = (string) Str::uuid();
    $payload = awardPayload($matchId, $winner, [$loser]);

    $first = $this->withHeaders(awardHeaders())->postJson('/internal/award-win', $payload);
    $first->assertOk()->assertJsonPath('winner.coins', 100);

    $second = $this->withHeaders(awardHeaders())->postJson('/internal/award-win', $payload);
    $second->assertOk()
        ->assertJsonPath('match_id', $matchId)
        ->assertJsonPath('already_awarded', true);

    // Balances only moved once.
    expect($winner->fresh()->coins)->toBe(100)
        ->and($winner->fresh()->wins)->toBe(1)
        ->and($loser->fresh()->losses)->toBe(1);

    // Exactly one ledger entry.
    expect(CoinTransaction::where('match_id', $matchId)->count())->toBe(1);
});
