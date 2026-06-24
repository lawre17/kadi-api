<?php

use App\Models\MatchPlayer;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Per-match private channel. Only participants (rows in match_players) may
// subscribe — this is what authorizes a client to receive game state.
// The RN client authorizes with a bearer token; the auth:sanctum middleware
// on /broadcasting/auth resolves and sets the request user.
Broadcast::channel('match.{matchId}', function ($user, string $matchId) {
    return MatchPlayer::where('match_id', $matchId)
        ->where('user_id', $user->id)
        ->exists();
});
