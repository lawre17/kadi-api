<?php

use App\Events\ReverbPing;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Backstop voice-clip cleanup for abandoned games (the main cleanup is inline on
// game-finish in MatchPlayController). Requires the Forge scheduler cron to run.
Schedule::command('voice:prune')->hourly();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Diagnostic: broadcast a ping over Reverb to verify the realtime pipeline.
Artisan::command('kadi:ping {message=hello}', function (string $message) {
    ReverbPing::dispatch($message);
    $this->info("Broadcast ping on channel 'kadi' (event 'ping'): {$message}");
})->purpose('Broadcast a test ping over Reverb');
