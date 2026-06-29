<?php

use App\Events\TournamentFinished;
use App\Events\TournamentMatchReady;
use App\Events\TournamentUpdate;
use App\Models\Tournament;
use App\Models\TournamentPlayer;
use App\Models\TournamentTable;
use App\Models\User;
use App\Services\TournamentService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

function tNodeUrl(string $path): string
{
    return rtrim((string) config('services.node.url'), '/').$path;
}

// Fake the Node engine's table-seeding endpoint, echoing the seated roster and
// handing back a fresh match id per call.
function fakeEngineTables(): void
{
    $seq = 0;
    Http::fake([
        tNodeUrl('/tournament-matches') => function ($request) use (&$seq) {
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
                'matchId' => "tmatch-{$seq}",
                'code' => "COD{$seq}",
                'roster' => $roster,
                'states' => [],
            ], 201);
        },
    ]);
}

test('a host creates a tournament and players join', function () {
    $host = User::factory()->create();
    $p2 = User::factory()->create();
    Event::fake([TournamentUpdate::class, TournamentMatchReady::class, TournamentFinished::class]);

    Sanctum::actingAs($host);
    $create = $this->postJson('/api/tournaments', ['tableSize' => 2])->assertCreated();
    $code = $create->json('code');
    $id = $create->json('id');

    expect($create->json('status'))->toBe('registering');
    expect($create->json('settings') ?? null)->toBeNull(); // settings not exposed in summary

    Sanctum::actingAs($p2);
    $this->postJson("/api/tournaments/{$code}/join")->assertOk()
        ->assertJsonPath('status', 'registering');

    $this->assertDatabaseHas('tournament_players', ['tournament_id' => $id, 'user_id' => $host->id]);
    $this->assertDatabaseHas('tournament_players', ['tournament_id' => $id, 'user_id' => $p2->id]);
    Event::assertDispatched(TournamentUpdate::class);
});

test('only the host can start, and at least two players are required', function () {
    $host = User::factory()->create();
    Event::fake([TournamentUpdate::class, TournamentMatchReady::class]);

    Sanctum::actingAs($host);
    $id = $this->postJson('/api/tournaments', ['tableSize' => 2])->json('id');

    // Solo can't start.
    $this->postJson("/api/tournaments/{$id}/start")->assertStatus(409);

    $other = User::factory()->create();
    Sanctum::actingAs($other);
    $this->postJson("/api/tournaments/{$id}/start")->assertStatus(403);
});

test('a single-elimination bracket runs to a champion across rounds', function () {
    $users = User::factory()->count(4)->create();
    [$host] = [$users[0]];

    Event::fake([TournamentUpdate::class, TournamentMatchReady::class, TournamentFinished::class]);
    fakeEngineTables();

    // Create + everyone joins.
    Sanctum::actingAs($host);
    $create = $this->postJson('/api/tournaments', ['tableSize' => 2])->assertCreated();
    $id = $create->json('id');
    $code = $create->json('code');

    foreach ($users->slice(1) as $u) {
        Sanctum::actingAs($u);
        $this->postJson("/api/tournaments/{$code}/join")->assertOk();
    }

    // Host starts → round 1 seated as 2 heads-up tables.
    Sanctum::actingAs($host);
    $this->postJson("/api/tournaments/{$id}/start")->assertOk()
        ->assertJsonPath('status', 'running');

    $round1 = TournamentTable::where('tournament_id', $id)->where('round', 1)->get();
    expect($round1)->toHaveCount(2);
    expect($round1->every(fn ($t) => count($t->player_user_ids) === 2))->toBeTrue();
    Event::assertDispatched(TournamentMatchReady::class);

    // Finish both round-1 tables — the first seated player wins each.
    $service = app(TournamentService::class);
    foreach ($round1 as $table) {
        $service->recordTableResult($table->match_id, (int) $table->player_user_ids[0]);
    }

    // Round 2 should be seated automatically with the 2 winners.
    $tournament = Tournament::find($id);
    expect($tournament->current_round)->toBe(2);
    $round2 = TournamentTable::where('tournament_id', $id)->where('round', 2)->get();
    expect($round2)->toHaveCount(1);
    expect($round2->first()->status)->toBe('running');

    // 3 engine tables were seated in total (2 + 1).
    Http::assertSentCount(3);

    // Finish the final → champion.
    $finalTable = $round2->first();
    $championId = (int) $finalTable->player_user_ids[0];
    $service->recordTableResult($finalTable->match_id, $championId);

    $tournament->refresh();
    expect($tournament->status)->toBe('finished');
    expect((int) $tournament->winner_user_id)->toBe($championId);

    $this->assertDatabaseHas('tournament_players', [
        'tournament_id' => $id,
        'user_id' => $championId,
        'status' => 'champion',
        'place' => 1,
    ]);
    expect(TournamentPlayer::where('tournament_id', $id)->where('status', 'eliminated')->count())->toBe(3);

    // Champion earned a tournament win.
    expect((int) User::find($championId)->wins)->toBe(1);

    Event::assertDispatched(TournamentFinished::class);
});
