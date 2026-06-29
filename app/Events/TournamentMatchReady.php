<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A round has been seated. Carries every table for the round so each client can
 * find the one containing its own user id and jump into that match. Players with
 * a bye (no table this round) are listed separately.
 */
class TournamentMatchReady implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, array<string, mixed>>  $tables  [{matchId, userIds, roster}]
     * @param  array<int, int>  $byes  user ids that auto-advance this round
     */
    public function __construct(
        public string $tournamentId,
        public int $round,
        public array $tables,
        public array $byes,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("tournament.{$this->tournamentId}");
    }

    public function broadcastAs(): string
    {
        return 'matchReady';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'round' => $this->round,
            'tables' => $this->tables,
            'byes' => $this->byes,
        ];
    }
}
