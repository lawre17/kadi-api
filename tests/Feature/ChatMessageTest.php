<?php

use App\Events\ChatMessage;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

test('a participant can send a chat message which is broadcast (not stored)', function () {
    Event::fake();

    $player = User::factory()->create();
    GameMatch::create(['id' => 'match-c1', 'status' => 'playing', 'host_user_id' => $player->id]);
    MatchPlayer::create(['match_id' => 'match-c1', 'user_id' => $player->id, 'seat_index' => 0]);

    Sanctum::actingAs($player);

    $this->postJson('/api/matches/match-c1/chat', ['text' => 'Good game!'])
        ->assertOk()
        ->assertJsonPath('ok', true);

    Event::assertDispatched(ChatMessage::class, function (ChatMessage $e) use ($player) {
        return $e->matchId === 'match-c1'
            && $e->fromUserId === $player->id
            && $e->fromName === $player->name
            && $e->text === 'Good game!';
    });
});

test('chat collapses whitespace and rejects an empty message', function () {
    Event::fake();

    $player = User::factory()->create();
    GameMatch::create(['id' => 'match-c2', 'status' => 'playing', 'host_user_id' => $player->id]);
    MatchPlayer::create(['match_id' => 'match-c2', 'user_id' => $player->id, 'seat_index' => 0]);

    Sanctum::actingAs($player);

    // Whitespace is collapsed to single spaces and trimmed.
    $this->postJson('/api/matches/match-c2/chat', ['text' => "  hey    there\n\nyou  "])
        ->assertOk();

    Event::assertDispatched(ChatMessage::class, fn (ChatMessage $e) => $e->text === 'hey there you');

    // A whitespace-only message is rejected.
    $this->postJson('/api/matches/match-c2/chat', ['text' => "   \n  "])
        ->assertStatus(422);
});

test('a non-participant cannot send a chat message', function () {
    Event::fake();

    $participant = User::factory()->create();
    $stranger = User::factory()->create();
    GameMatch::create(['id' => 'match-c3', 'status' => 'playing', 'host_user_id' => $participant->id]);
    MatchPlayer::create(['match_id' => 'match-c3', 'user_id' => $participant->id, 'seat_index' => 0]);

    Sanctum::actingAs($stranger);

    $this->postJson('/api/matches/match-c3/chat', ['text' => 'let me in'])
        ->assertForbidden();

    Event::assertNotDispatched(ChatMessage::class);
});
