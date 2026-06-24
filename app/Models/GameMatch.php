<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['id', 'status', 'host_user_id', 'winner_user_id', 'settings', 'started_at', 'finished_at'])]
class GameMatch extends Model
{
    protected $table = 'matches';

    // The Node game service supplies a string/uuid primary key.
    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function players(): HasMany
    {
        return $this->hasMany(MatchPlayer::class, 'match_id');
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'winner_user_id');
    }
}
