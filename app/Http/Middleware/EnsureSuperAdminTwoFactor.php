<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureSuperAdminTwoFactor
{
    public function handle(Request $request, Closure $next)
    {
        // Los tokens emitidos antes de configurar 2FA solo reciben la
        // capacidad two-factor:setup. Un token normal con '*' satisface
        // platform:admin y conserva compatibilidad con Sanctum actingAs.
        $user = $request->user('sanctum');
        if (! $user?->twoFactorEnabled() || ! $user->currentAccessToken()?->can('platform:admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Configura la autenticación de dos factores para continuar.',
                'restriction' => 'two_factor_setup_required',
            ], 403);
        }

        return $next($request);
    }
}
