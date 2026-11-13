<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = $request->user();

        if (!$user || !in_array($user->role, $roles, true)) {
            return response()->json([
                'success' => false,
                'code'    => 'FORBIDDEN',
                'message' => 'Anda tidak punya akses',
            ], 403);
        }

        return $next($request);
    }
}
