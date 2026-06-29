<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Roster / status / round snapshot for a tournament. Broadcast whenever the
 * tournament's shape changes (join, start, a table result, round advance).
 */
class TournamentUpdate implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $tournament
     */
    public function __construct(public string $tournamentId, public array $tournament) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("tournament.{$this->tournamentId}");
    }

    public function broadcastAs(): string
    {
        return 'update';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['tournament' => $this->tournament];
    }
}
