<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MatchAwarded implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public string $matchId, public int $winnerUserId, public int $coins) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("match.{$this->matchId}");
    }

    public function broadcastAs(): string
    {
        return 'awarded';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'winnerUserId' => $this->winnerUserId,
            'coins' => $this->coins,
        ];
    }
}
