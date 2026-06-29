<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tournament_id', 'user_id', 'status', 'points', 'place', 'eliminated_round',
])]
class TournamentPlayer extends Model
{
    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'place' => 'integer',
            'eliminated_round' => 'integer',
        ];
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
