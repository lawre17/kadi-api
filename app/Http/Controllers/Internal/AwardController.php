<?php

namespace App\Http\Controllers\Internal;

use App\Actions\AwardMatchWin;
use App\Http\Controllers\Controller;
use App\Http\Requests\AwardWinRequest;
use Illuminate\Http\JsonResponse;

class AwardController extends Controller
{
    public function awardWin(AwardWinRequest $request, AwardMatchWin $awardMatchWin): JsonResponse
    {
        $matchId = (string) $request->string('match_id');
        $winnerId = (int) $request->integer('winner_user_id');
        $participants = collect($request->input('participants', []))
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $settings = $request->input('settings');

        $result = $awardMatchWin->handle($matchId, $winnerId, $participants, $settings);

        if ($result['already_awarded']) {
            return response()->json([
                'match_id' => $result['match_id'],
                'already_awarded' => true,
            ]);
        }

        return response()->json([
            'match_id' => $result['match_id'],
            'winner' => [
                'user_id' => $result['winner_user_id'],
                'coins' => $result['coins'],
            ],
        ]);
    }
}
