<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GameStateUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  mixed  $state  an authoritative GameState snapshot from the engine
     */
    public function __construct(public string $matchId, public mixed $state) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("match.{$this->matchId}");
    }

    public function broadcastAs(): string
    {
        return 'gameState';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['state' => $this->state];
    }
}
