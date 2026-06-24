<?php

namespace App\Http\Controllers;

use App\Models\GameMatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MatchController extends Controller
{
    /**
     * Create a room. v1 just generates ids; room lifecycle largely lives in
     * the Node game service. We persist a 'lobby' row as a convenience.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'settings' => ['nullable', 'array'],
        ]);

        $id = (string) Str::uuid();
        $code = strtoupper(Str::random(5));

        GameMatch::create([
            'id' => $id,
            'status' => 'lobby',
            'settings' => $validated['settings'] ?? null,
            'started_at' => null,
        ]);

        return response()->json([
            'match' => [
                'id' => $id,
                'code' => $code,
            ],
        ], 201);
    }
}
