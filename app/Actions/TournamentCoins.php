<?php

namespace App\Actions;

use App\Models\CoinTransaction;
use App\Models\Tournament;
use App\Models\TournamentPlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The coin economy for tournaments: escrow each entrant's buy-in into the
 * tournament's prize pool, refund it if they leave before it starts, and pay the
 * pool to the champion at the end. Every move is recorded in the coin ledger and
 * guarded by row locks; a 0-coin (free) tournament is a no-op throughout.
 */
class TournamentCoins
{
    /**
     * Deduct the buy-in and add it to the prize pool. Throws a 422 if the user
     * can't afford it.
     */
    public function charge(int $userId, Tournament $tournament): void
    {
        $buyIn = (int) $tournament->buy_in;
        if ($buyIn <= 0) {
            return;
        }

        DB::transaction(function () use ($userId, $tournament, $buyIn) {
            $user = User::whereKey($userId)->lockForUpdate()->first();
            if (! $user) {
                throw ValidationException::withMessages(['buyIn' => ['Account not found.']]);
            }
            if ((int) $user->coins < $buyIn) {
                throw ValidationException::withMessages([
                    'buyIn' => ["You need 🪙 {$buyIn} to enter this tournament."],
                ]);
            }

            $user->decrement('coins', $buyIn);
            CoinTransaction::create([
                'user_id' => $userId,
                'tournament_id' => $tournament->id,
                'amount' => -$buyIn,
                'reason' => 'tournament_buyin',
            ]);
            Tournament::whereKey($tournament->id)->increment('prize_pool', $buyIn);
        });
    }

    /**
     * Return a single entrant's buy-in and shrink the pool. Used when a player
     * leaves during registration.
     */
    public function refund(int $userId, Tournament $tournament): void
    {
        $buyIn = (int) $tournament->buy_in;
        if ($buyIn <= 0) {
            return;
        }

        DB::transaction(function () use ($userId, $tournament, $buyIn) {
            User::whereKey($userId)->increment('coins', $buyIn);
            CoinTransaction::create([
                'user_id' => $userId,
                'tournament_id' => $tournament->id,
                'amount' => $buyIn,
                'reason' => 'tournament_refund',
            ]);
            Tournament::whereKey($tournament->id)
                ->where('prize_pool', '>=', $buyIn)
                ->decrement('prize_pool', $buyIn);
        });
    }

    /**
     * Refund every registered entrant (tournament cancelled before it started).
     */
    public function refundAll(Tournament $tournament): void
    {
        $userIds = TournamentPlayer::where('tournament_id', $tournament->id)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($userIds as $uid) {
            $this->refund($uid, $tournament);
        }
    }

    /**
     * Pay the prize pool to the champion. Idempotent: a second call is a no-op
     * once a prize has been recorded for the tournament. Returns the prize paid.
     */
    public function payout(Tournament $tournament, int $champUserId): int
    {
        $prize = (int) $tournament->prize_pool;
        if ($prize <= 0) {
            return 0;
        }

        return DB::transaction(function () use ($tournament, $champUserId, $prize) {
            $already = CoinTransaction::where('tournament_id', $tournament->id)
                ->where('reason', 'tournament_prize')
                ->lockForUpdate()
                ->exists();
            if ($already) {
                return $prize;
            }

            User::whereKey($champUserId)->increment('coins', $prize);
            CoinTransaction::create([
                'user_id' => $champUserId,
                'tournament_id' => $tournament->id,
                'amount' => $prize,
                'reason' => 'tournament_prize',
            ]);

            return $prize;
        });
    }
}
