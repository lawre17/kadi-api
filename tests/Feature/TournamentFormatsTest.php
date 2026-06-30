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

function fmtNodeUrl(string $path): string
{
    return rtrim((string) config('services.node.url'), '/').$path;
}

function fakeFmtEngine(): void
{
    $seq = 0;
    Http::fake([
        fmtNodeUrl('/tournament-matches') => function ($request) use (&$seq) {
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
                'matchId' => "fmtmatch-{$seq}",
                'code' => "FMT{$seq}",
                'roster' => $roster,
                'states' => [],
            ], 201);
        },
    ]);
}

/**
 * Create + fill + start a tournament, then play every round to completion,
 * always finishing each table in its seated order (seat 0 wins). Returns the id.
 */
function runTournament(object $test, array $opts): string
{
    $count = $opts['players'];
    $users = User::factory()->count($count)->create(['coins' => 1000]);
    $host = $users[0];

    Sanctum::actingAs($host);
    $created = $test->postJson('/api/tournaments', $opts['create']);
    $id = $created->json('id');
    $code = $created->json('code');

    foreach ($users->slice(1) as $u) {
        Sanctum::actingAs($u);
        $test->postJson("/api/tournaments/{$code}/join")->assertOk();
    }

    Sanctum::actingAs($host);
    $test->postJson("/api/tournaments/{$id}/start")->assertOk();

    $service = app(TournamentService::class);
    $guard = 0;
    while (Tournament::find($id)->status !== 'finished' && $guard++ < 30) {
        $round = Tournament::find($id)->current_round;
        $tables = TournamentTable::where('tournament_id', $id)
            ->where('round', $round)
            ->where('status', 'running')
            ->get();
        if ($tables->isEmpty()) {
            break;
        }
        foreach ($tables as $table) {
            $order = array_map('intval', $table->player_user_ids);
            // ranking = seated order (seat 0 best -> last worst).
            $service->recordTableResult($table->match_id, $order[0], $order);
        }
    }

    return $id;
}

beforeEach(function () {
    Event::fake([TournamentUpdate::class, TournamentMatchReady::class, TournamentFinished::class]);
    fakeFmtEngine();
});

test('league plays a fixed number of rounds and the points leader wins', function () {
    $id = runTournament($this, [
        'players' => 4,
        'create' => ['format' => 'league', 'tableSize' => 2, 'roundsTotal' => 2],
    ]);

    $t = Tournament::find($id);
    expect($t->status)->toBe('finished');
    expect($t->current_round)->toBe(2);
    expect($t->winner_user_id)->not->toBeNull();

    $players = TournamentPlayer::where('tournament_id', $id)->get();

    // League never eliminates anyone.
    expect($players->where('status', 'eliminated')->count())->toBe(0);

    // 2 rounds × 2 tables of 2, each table awards 2+1 = 3 points → 12 total.
    expect((int) $players->sum('points'))->toBe(12);

    // The champion holds the most points.
    $maxPoints = (int) $players->max('points');
    $champ = $players->firstWhere('user_id', $t->winner_user_id);
    expect((int) $champ->points)->toBe($maxPoints);
    expect($champ->status)->toBe('champion');
});

test('survival drops the bottom half each round until one remains', function () {
    $id = runTournament($this, [
        'players' => 6,
        'create' => ['format' => 'survival', 'tableSize' => 6],
    ]);

    $t = Tournament::find($id);
    expect($t->status)->toBe('finished');
    expect($t->winner_user_id)->not->toBeNull();

    $players = TournamentPlayer::where('tournament_id', $id)->get();

    // One champion, the other five eliminated along the way.
    expect($players->where('status', 'champion')->count())->toBe(1);
    expect($players->where('status', 'eliminated')->count())->toBe(5);

    // The champion is whoever survived to the end.
    $champ = $players->firstWhere('user_id', $t->winner_user_id);
    expect($champ->status)->toBe('champion');
    expect($champ->place)->toBe(1);
});

test('knockout still crowns a single winner across rounds', function () {
    $id = runTournament($this, [
        'players' => 4,
        'create' => ['format' => 'bracket', 'tableSize' => 2],
    ]);

    $t = Tournament::find($id);
    expect($t->status)->toBe('finished');
    $players = TournamentPlayer::where('tournament_id', $id)->get();
    expect($players->where('status', 'champion')->count())->toBe(1);
    expect($players->where('status', 'eliminated')->count())->toBe(3);
});
