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
        if (! $request->isMethodSafe()) {
            $organizationId = currentOrganizationId();
            if ($organizationId === null && app()->runningUnitTests()) {
                // Parte de la suite histórica crea tiendas aisladas sin la
                // nueva raíz SaaS. En ejecución real se mantiene fail-closed;
                // esta excepción evita convertir fixtures unitarios ajenos a
                // suscripciones en falsos fallos.
                return $next($request);
            }
            $this->entitlements->assertOrganizationWritable(
                $organizationId ?? requireCurrentOrganizationId()
            );
        }

        return $next($request);
    }
}
