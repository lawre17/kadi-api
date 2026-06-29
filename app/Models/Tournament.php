<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'id', 'code', 'format', 'status', 'host_user_id', 'buy_in', 'prize_pool',
    'table_size', 'rounds_total', 'current_round', 'winner_user_id', 'settings',
])]
class Tournament extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::creating(function (Tournament $t) {
            if (empty($t->id)) {
                $t->id = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'buy_in' => 'integer',
            'prize_pool' => 'integer',
            'table_size' => 'integer',
            'rounds_total' => 'integer',
            'current_round' => 'integer',
        ];
    }

    public function players(): HasMany
    {
        return $this->hasMany(TournamentPlayer::class);
    }

    public function tables(): HasMany
    {
        return $this->hasMany(TournamentTable::class);
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'winner_user_id');
    }
}
