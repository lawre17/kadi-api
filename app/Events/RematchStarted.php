<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RematchStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, array<string, mixed>>  $roster
     */
    public function __construct(
        public string $matchId, // the OLD match channel everyone is still on
        public string $newMatchId,
        public array $roster,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("match.{$this->matchId}");
    }

    public function broadcastAs(): string
    {
        return 'rematchStarted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'newMatchId' => $this->newMatchId,
            'roster' => $this->roster,
        ];
    }
}
