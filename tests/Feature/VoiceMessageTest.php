<?php

use App\Events\VoiceMessage;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

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

    // Exactly one file landed under voice/ on the public disk.
    expect(Storage::disk('public')->files('voice'))->toHaveCount(1);

    Event::assertDispatched(VoiceMessage::class, function (VoiceMessage $e) use ($player) {
        return $e->matchId === 'match-v1'
            && $e->fromUserId === $player->id
            && $e->fromName === $player->name
            && str_contains($e->url, 'voice/');
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
