<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['match_id', 'user_id', 'seat_index', 'is_ai', 'result'])]
class MatchPlayer extends Model
{
    protected function casts(): array
    {
        return [
            'is_ai' => 'boolean',
            'seat_index' => 'integer',
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
