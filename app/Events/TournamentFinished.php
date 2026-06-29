<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The tournament has a champion. Carries the winner and final standings, plus
 * the prize awarded (coins) for the results screen.
 */
class TournamentFinished implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, array<string, mixed>>  $standings
     */
    public function __construct(
        public string $tournamentId,
        public ?int $winnerUserId,
        public string $winnerName,
        public int $prize,
        public array $standings,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("tournament.{$this->tournamentId}");
    }

    public function broadcastAs(): string
    {
        return 'finished';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'winnerUserId' => $this->winnerUserId,
            'winnerName' => $this->winnerName,
            'prize' => $this->prize,
            'standings' => $this->standings,
        ];
    }
}
