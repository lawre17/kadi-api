<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyInternalSecret
{
    /**
     * Guard internal endpoints with a shared-secret header. The Node game
     * service must send X-Internal-Secret matching services.internal.secret.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.internal.secret');
        $provided = $request->header('X-Internal-Secret');

        if (! is_string($provided) || ! is_string($expected) || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
