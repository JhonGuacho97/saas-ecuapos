<?php

namespace App\Http\Middleware;

use App\Services\SaaS\EntitlementService;
use Closure;
use Illuminate\Http\Request;

class EnsureActiveSubscription
{
    public function __construct(private readonly EntitlementService $entitlements)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        // Una prueba vencida conserva acceso de lectura para consultar y
        // exportar sus datos, pero no permite crear ni modificar registros.
        if (! $request->isMethodSafe() && currentOrganizationId()) {
            $this->entitlements->assertOrganizationWritable(currentOrganizationId());
        }

        return $next($request);
    }
}
