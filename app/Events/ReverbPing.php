<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A tiny diagnostic event used to verify the Reverb realtime pipeline end to
 * end (broadcast -> Reverb -> subscribed client). Broadcasts NOW (no queue) on
 * the public "kadi" channel as event name "ping". Safe to remove once the real
 * game-channel broadcasting lands. Trigger with: php artisan kadi:ping "hello".
 */
class ReverbPing implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public string $message)
    {
    }

    public function broadcastOn(): Channel
    {
        return new Channel('kadi');
    }

    public function broadcastAs(): string
    {
        return 'ping';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'message' => $this->message,
            'at' => now()->toIso8601String(),
        ];
    }
}
