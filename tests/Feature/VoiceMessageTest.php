<?php

use App\Events\VoiceMessage;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

function voiceNodeUrl(string $path): string
{
    return rtrim((string) config('services.node.url'), '/').$path;
}

test('a participant can upload a voice clip which is stored and broadcast', function () {
    Storage::fake('public');
    Event::fake();

    $player = User::factory()->create();
    GameMatch::create(['id' => 'match-v1', 'status' => 'playing', 'host_user_id' => $player->id]);
    MatchPlayer::create(['match_id' => 'match-v1', 'user_id' => $player->id, 'seat_index' => 0]);

    Sanctum::actingAs($player);

    $clip = UploadedFile::fake()->create('clip.m4a', 64, 'audio/mp4');

    $response = $this->postJson('/api/matches/match-v1/voice', ['clip' => $clip]);

    $response->assertOk()->assertJsonStructure(['url']);

    // Exactly one file landed, grouped under voice/{matchId}/ on the public disk.
    expect(Storage::disk('public')->files('voice'))->toBeEmpty();
    expect(Storage::disk('public')->files('voice/match-v1'))->toHaveCount(1);

    Event::assertDispatched(VoiceMessage::class, function (VoiceMessage $e) use ($player) {
        return $e->matchId === 'match-v1'
            && $e->fromUserId === $player->id
            && $e->fromName === $player->name
            && str_contains($e->url, 'voice/match-v1/');
    });
});

test('a non-participant cannot upload a voice clip', function () {
    Storage::fake('public');
    Event::fake();

    $participant = User::factory()->create();
    $stranger = User::factory()->create();
    GameMatch::create(['id' => 'match-v2', 'status' => 'playing', 'host_user_id' => $participant->id]);
    MatchPlayer::create(['match_id' => 'match-v2', 'user_id' => $participant->id, 'seat_index' => 0]);

    Sanctum::actingAs($stranger);

    $clip = UploadedFile::fake()->create('clip.m4a', 64, 'audio/mp4');

    $this->postJson('/api/matches/match-v2/voice', ['clip' => $clip])
        ->assertForbidden();

    expect(Storage::disk('public')->files('voice'))->toBeEmpty();
    Event::assertNotDispatched(VoiceMessage::class);
});

test('finishing a match deletes that match voice clip folder', function () {
    Storage::fake('public');
    Event::fake();

    $winner = User::factory()->create(['coins' => 0, 'wins' => 0]);
    $loser = User::factory()->create(['coins' => 0, 'losses' => 0]);

    GameMatch::create(['id' => 'match-vf', 'status' => 'playing', 'host_user_id' => $winner->id]);
    MatchPlayer::create(['match_id' => 'match-vf', 'user_id' => $winner->id, 'seat_index' => 0]);
    MatchPlayer::create(['match_id' => 'match-vf', 'user_id' => $loser->id, 'seat_index' => 1]);

    // Seed a clip for this match.
    Storage::disk('public')->put('voice/match-vf/old-clip.m4a', 'data');
    expect(Storage::disk('public')->files('voice/match-vf'))->toHaveCount(1);

    Sanctum::actingAs($winner);

    Http::fake([
        voiceNodeUrl('/matches/match-vf/move') => Http::response([
            'states' => [['turn' => 1]],
            'finished' => true,
            'winnerUserId' => (string) $winner->id,
        ]),
    ]);

    $this->postJson('/api/matches/match-vf/move', ['move' => ['type' => 'play']])
        ->assertOk()
        ->assertJsonPath('finished', true);

    // The match's clip folder is gone once the game finished.
    expect(Storage::disk('public')->exists('voice/match-vf'))->toBeFalse();
    expect(Storage::disk('public')->files('voice/match-vf'))->toBeEmpty();
});
