<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user('sanctum')?->is_super_admin) {
            return response()->json([
                'success' => false,
                'message' => 'Esta acción está reservada para la administración de EcuaPos.',
            ], 403);
        }

        return $next($request);
    }
}
