<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RematchUpdate implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, string>  $ready  userIds who've accepted
     */
    public function __construct(
        public string $matchId,
        public array $ready,
        public int $total,
        public bool $cannot,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("match.{$this->matchId}");
    }

    public function broadcastAs(): string
    {
        return 'rematch';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'ready' => array_map('strval', $this->ready),
            'total' => $this->total,
            'cannot' => $this->cannot,
        ];
    }
}
