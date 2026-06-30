<?php

use App\Actions\TournamentCoins;
use App\Events\TournamentFinished;
use App\Events\TournamentMatchReady;
use App\Events\TournamentUpdate;
use App\Models\Tournament;
use App\Models\TournamentTable;
use App\Models\User;
use App\Services\TournamentService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

function coinNodeUrl(string $path): string
{
    return rtrim((string) config('services.node.url'), '/').$path;
}

function fakeCoinEngine(): void
{
    $seq = 0;
    Http::fake([
        coinNodeUrl('/tournament-matches') => function ($request) use (&$seq) {
            $seq++;
            $roster = [];
            foreach ($request['players'] as $i => $p) {
                $roster[] = [
                    'userId' => (string) $p['userId'],
                    'name' => $p['name'],
                    'seatIndex' => $i,
                    'engineId' => "p{$i}",
                    'isAI' => false,
                ];
            }

            return Http::response([
                'matchId' => "ctmatch-{$seq}",
                'code' => "CTC{$seq}",
                'roster' => $roster,
                'states' => [],
            ], 201);
        },
    ]);
}

beforeEach(function () {
    Event::fake([TournamentUpdate::class, TournamentMatchReady::class, TournamentFinished::class]);
});

test('creating a paid tournament charges the host into the pool', function () {
    $host = User::factory()->create(['coins' => 1000]);
    Sanctum::actingAs($host);

    $res = $this->postJson('/api/tournaments', ['tableSize' => 2, 'buyIn' => 100])
        ->assertCreated()
        ->assertJsonPath('buyIn', 100)
        ->assertJsonPath('prizePool', 100);

    expect((int) $host->fresh()->coins)->toBe(900);
    $this->assertDatabaseHas('coin_transactions', [
        'user_id' => $host->id,
        'tournament_id' => $res->json('id'),
        'amount' => -100,
        'reason' => 'tournament_buyin',
    ]);
});

test('joining a paid tournament charges the joiner and grows the pool', function () {
    $host = User::factory()->create(['coins' => 1000]);
    $p2 = User::factory()->create(['coins' => 1000]);

    Sanctum::actingAs($host);
    $code = $this->postJson('/api/tournaments', ['buyIn' => 100])->json('code');

    Sanctum::actingAs($p2);
    $this->postJson("/api/tournaments/{$code}/join")
        ->assertOk()
        ->assertJsonPath('prizePool', 200);

    expect((int) $p2->fresh()->coins)->toBe(900);
});

test('a player who cannot afford the buy-in is rejected and not seated', function () {
    $host = User::factory()->create(['coins' => 1000]);
    $poor = User::factory()->create(['coins' => 50]);

    Sanctum::actingAs($host);
    $created = $this->postJson('/api/tournaments', ['buyIn' => 100]);
    $id = $created->json('id');
    $code = $created->json('code');

    Sanctum::actingAs($poor);
    $this->postJson("/api/tournaments/{$code}/join")->assertStatus(422);

    expect((int) $poor->fresh()->coins)->toBe(50);
    $this->assertDatabaseMissing('tournament_players', [
        'tournament_id' => $id,
        'user_id' => $poor->id,
    ]);
    // Pool unchanged (host's 100 only).
    expect((int) Tournament::find($id)->prize_pool)->toBe(100);
});

test('leaving during registration refunds the buy-in', function () {
    $host = User::factory()->create(['coins' => 1000]);
    $p2 = User::factory()->create(['coins' => 1000]);

    Sanctum::actingAs($host);
    $created = $this->postJson('/api/tournaments', ['buyIn' => 100]);
    $id = $created->json('id');
    $code = $created->json('code');

    Sanctum::actingAs($p2);
    $this->postJson("/api/tournaments/{$code}/join")->assertOk();
    $this->postJson("/api/tournaments/{$id}/leave")->assertOk();

    expect((int) $p2->fresh()->coins)->toBe(1000);
    expect((int) Tournament::find($id)->prize_pool)->toBe(100);
    $this->assertDatabaseMissing('tournament_players', [
        'tournament_id' => $id,
        'user_id' => $p2->id,
    ]);
});

test('the host leaving cancels the tournament and refunds everyone', function () {
    $host = User::factory()->create(['coins' => 1000]);
    $p2 = User::factory()->create(['coins' => 1000]);

    Sanctum::actingAs($host);
    $created = $this->postJson('/api/tournaments', ['buyIn' => 100]);
    $id = $created->json('id');
    $code = $created->json('code');

    Sanctum::actingAs($p2);
    $this->postJson("/api/tournaments/{$code}/join")->assertOk();

    Sanctum::actingAs($host);
    $this->postJson("/api/tournaments/{$id}/leave")->assertOk();

    $t = Tournament::find($id);
    expect($t->status)->toBe('finished');
    expect($t->winner_user_id)->toBeNull();
    expect((int) $t->prize_pool)->toBe(0);
    expect((int) $host->fresh()->coins)->toBe(1000);
    expect((int) $p2->fresh()->coins)->toBe(1000);
});

test('the champion is paid the prize pool, and payout is idempotent', function () {
    $users = User::factory()->count(4)->create(['coins' => 1000]);
    $host = $users[0];
    fakeCoinEngine();

    Sanctum::actingAs($host);
    $created = $this->postJson('/api/tournaments', ['tableSize' => 2, 'buyIn' => 100]);
    $id = $created->json('id');
    $code = $created->json('code');

    foreach ($users->slice(1) as $u) {
        Sanctum::actingAs($u);
        $this->postJson("/api/tournaments/{$code}/join")->assertOk();
    }

    // Pool = 4 × 100; everyone down to 900.
    expect((int) Tournament::find($id)->prize_pool)->toBe(400);

    Sanctum::actingAs($host);
    $this->postJson("/api/tournaments/{$id}/start")->assertOk();

    $service = app(TournamentService::class);

    // Finish round 1 (first seat wins each table).
    foreach (TournamentTable::where('tournament_id', $id)->where('round', 1)->get() as $table) {
        $service->recordTableResult($table->match_id, (int) $table->player_user_ids[0]);
    }
    // Finish the final.
    $final = TournamentTable::where('tournament_id', $id)->where('round', 2)->first();
    $championId = (int) $final->player_user_ids[0];
    $service->recordTableResult($final->match_id, $championId);

    // Champion: 900 + 400 pot = 1300.
    expect((int) User::find($championId)->fresh()->coins)->toBe(1300);
    $this->assertDatabaseHas('coin_transactions', [
        'tournament_id' => $id,
        'user_id' => $championId,
        'amount' => 400,
        'reason' => 'tournament_prize',
    ]);

    // Paying again is a no-op.
    app(TournamentCoins::class)->payout(Tournament::find($id), $championId);
    expect((int) User::find($championId)->fresh()->coins)->toBe(1300);
});
