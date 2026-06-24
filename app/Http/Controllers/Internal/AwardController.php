<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\AwardWinRequest;
use App\Models\CoinTransaction;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AwardController extends Controller
{
    private const WIN_REWARD = 100;

    public function awardWin(AwardWinRequest $request): JsonResponse
    {
        $matchId = (string) $request->string('match_id');
        $winnerId = (int) $request->integer('winner_user_id');
        $participants = collect($request->input('participants', []))
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $settings = $request->input('settings');

        return DB::transaction(function () use ($matchId, $winnerId, $participants, $settings) {
            // Lock the existing match row (if any) so concurrent awards serialize.
            $match = GameMatch::query()->lockForUpdate()->find($matchId);

            // Idempotent: a finished match has already been awarded.
            if ($match && $match->status === 'finished') {
                return response()->json([
                    'match_id' => $match->id,
                    'already_awarded' => true,
                ]);
            }

            $now = now();

            if ($match) {
                $match->fill([
                    'status' => 'finished',
                    'winner_user_id' => $winnerId,
                    'settings' => $settings ?? $match->settings,
                    'finished_at' => $now,
                ])->save();
            } else {
                $match = GameMatch::create([
                    'id' => $matchId,
                    'status' => 'finished',
                    'winner_user_id' => $winnerId,
                    'settings' => $settings,
                    'finished_at' => $now,
                ]);
            }

            // Record each human participant as a player with a result.
            foreach ($participants as $userId) {
                MatchPlayer::updateOrCreate(
                    ['match_id' => $matchId, 'user_id' => $userId],
                    ['result' => $userId === $winnerId ? 'won' : 'lost', 'is_ai' => false]
                );
            }

            // Ledger entry — unique (match_id, reason) is a backstop against
            // double-award even if the status check above were bypassed.
            CoinTransaction::create([
                'user_id' => $winnerId,
                'match_id' => $matchId,
                'amount' => self::WIN_REWARD,
                'reason' => 'win',
            ]);

            // Winner: +coins, +1 win.
            User::whereKey($winnerId)->update([
                'coins' => DB::raw('coins + '.self::WIN_REWARD),
                'wins' => DB::raw('wins + 1'),
            ]);

            // Losers: +1 loss each.
            $loserIds = $participants->reject(fn ($id) => $id === $winnerId)->values();
            if ($loserIds->isNotEmpty()) {
                User::whereIn('id', $loserIds)->update([
                    'losses' => DB::raw('losses + 1'),
                ]);
            }

            $newBalance = (int) User::whereKey($winnerId)->value('coins');

            return response()->json([
                'match_id' => $matchId,
                'winner' => [
                    'user_id' => $winnerId,
                    'coins' => $newBalance,
                ],
            ]);
        });
    }
}
