<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tournament_id', 'round', 'match_id', 'status', 'player_user_ids', 'winner_user_id',
])]
class TournamentTable extends Model
{
    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'player_user_ids' => 'array',
        ];
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }
}
