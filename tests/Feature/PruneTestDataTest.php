<?php

use App\Models\CoinTransaction;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\User;

/**
 * Seed one throwaway test user (@k.test) and one real user, each with a token,
 * a hosted match, a match_player and a coin_transaction. Assert db:prune-test-data
 * removes only the test user's rows and leaves the real user's untouched.
 */
function seedUserGraph(string $email, string $matchId): User
{
    $user = User::factory()->create(['email' => $email]);
    $user->createToken('test');

    GameMatch::create(['id' => $matchId, 'status' => 'finished', 'host_user_id' => $user->id]);
    MatchPlayer::create(['match_id' => $matchId, 'user_id' => $user->id, 'seat_index' => 0]);
    CoinTransaction::create(['user_id' => $user->id, 'match_id' => $matchId, 'amount' => 100, 'reason' => 'win']);

    return $user;
}

test('db:prune-test-data removes only test accounts and their data', function () {
    $test = seedUserGraph('throwaway@k.test', 'match-test');
    $real = seedUserGraph('player@example.com', 'match-real');

    $this->artisan('db:prune-test-data')->assertSuccessful();

    // Test user and all their data are gone.
    expect(User::find($test->id))->toBeNull();
    expect(GameMatch::find('match-test'))->toBeNull();
    expect(MatchPlayer::where('match_id', 'match-test')->exists())->toBeFalse();
    expect(CoinTransaction::where('match_id', 'match-test')->exists())->toBeFalse();
    $this->assertDatabaseMissing('personal_access_tokens', [
        'tokenable_type' => User::class,
        'tokenable_id' => $test->id,
    ]);

    // Real user and all their data remain.
    expect(User::find($real->id))->not->toBeNull();
    expect(GameMatch::find('match-real'))->not->toBeNull();
    expect(MatchPlayer::where('match_id', 'match-real')->exists())->toBeTrue();
    expect(CoinTransaction::where('match_id', 'match-real')->exists())->toBeTrue();
    $this->assertDatabaseHas('personal_access_tokens', [
        'tokenable_type' => User::class,
        'tokenable_id' => $real->id,
    ]);
});

test('db:prune-test-data --dry-run deletes nothing', function () {
    $test = seedUserGraph('throwaway@kadi.test', 'match-dry');

    $this->artisan('db:prune-test-data --dry-run')->assertSuccessful();

    // Still present after a dry run.
    expect(User::find($test->id))->not->toBeNull();
    expect(GameMatch::find('match-dry'))->not->toBeNull();
});

test('db:prune-test-data is idempotent when there is nothing to prune', function () {
    User::factory()->create(['email' => 'player@example.com']);

    $this->artisan('db:prune-test-data')->assertSuccessful();
    $this->artisan('db:prune-test-data')->assertSuccessful();

    expect(User::where('email', 'player@example.com')->exists())->toBeTrue();
});
