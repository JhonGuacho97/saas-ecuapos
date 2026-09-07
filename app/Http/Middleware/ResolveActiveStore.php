<?php

namespace App\Http\Middleware;

use App\Exceptions\SubscriptionRestrictionException;
use App\Models\Organization;
use App\Models\Store;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Resuelve y valida la "tienda activa" (Store) de cada request
 * autenticado. Ver el documento de planificación multitienda, sección 8.
 *
 * Regla central, pedida explícitamente: el store_id que manda el
 * frontend (header X-Store-Id) NUNCA se usa directo -- siempre se valida
 * contra la pertenencia real del usuario (user_store) antes de dejarlo
 * pasar. Mismo patrón defensivo que ya usa
 * AppBaseController::authorizeWarehouseAccess() para warehouse_id.
 *
 * Con exactamente UNA tienda accesible (el estado real de cualquier
 * instalación que venga de la Fase 2 de migración, hasta que se cree
 * una segunda Store), se resuelve sola sin necesitar que el frontend
 * mande el header -- nadie ve fricción nueva hasta que exista una
 * segunda tienda de verdad. Con 0 o 2+ tiendas sin header, no se
 * resuelve nada acá (los endpoints que necesiten una tienda resuelta lo
 * validan ellos mismos vía AppBaseController::currentStoreId()).
 *
 * Desde la Fase 13, también llama a setPermissionsTeamId() -- SIEMPRE,
 * incluso con null -- para que Spatie filtre cualquier chequeo de rol/
 * permiso (hasRole(), can(), etc.) por la tienda activa. Se llama
 * incondicionalmente (no solo cuando se resuelve una tienda) para que
 * ningún request herede por accidente el team_id de uno anterior en el
 * mismo proceso. Con 0/2+ tiendas sin header (team_id queda null),
 * hasRole()/can() no van a encontrar ninguna fila -- ver
 * UserRepository::storeUser(), que ahora asigna cada usuario nuevo a su
 * tienda para que este caso nunca se dé en la práctica.
 */
class ResolveActiveStore
{
    public function handle(Request $request, Closure $next)
    {
        // Guard explícito 'sanctum': $request->user() sin argumento
        // resuelve por el guard DEFAULT de config/auth.php (acá 'web',
        // de sesión) salvo que auth:sanctum ya haya corrido antes en la
        // misma request y haya hecho Auth::shouldUse('sanctum'). En
        // rutas que llevan store.context SIN auth:sanctum (ver
        // routes/api.php, front-setting -- pública para el login pero
        // también usada ya logueado) eso dejaba a $request->user()
        // devolviendo null SIEMPRE, aunque el request trajera un token
        // Bearer válido -- la tienda activa nunca se resolvía ahí.
        $user = $request->user('sanctum');
        if (! $user) {
            return $next($request);
        }

        // Una tienda desactivada (Store::is_active = false) se trata como
        // si el usuario no tuviera acceso a ella -- ni el auto-resolve de
        // una sola tienda ni un X-Store-Id explícito pueden "entrar" a
        // operar en ella. El toggle de activar/desactivar del CRUD de
        // Tiendas no tendría efecto real si solo ocultara la opción del
        // selector sin validar acá también.
        $storeIds = $user->stores()->where('stores.is_active', true)->pluck('stores.id');
        $requestedStoreId = $request->header('X-Store-Id');
        $resolvedStoreId = null;

        if ($requestedStoreId !== null && $requestedStoreId !== '') {
            if (! $storeIds->contains((int) $requestedStoreId)) {
                throw new AccessDeniedHttpException('No tiene acceso a esta tienda.');
            }
            $resolvedStoreId = (int) $requestedStoreId;
        } elseif ($storeIds->count() === 1) {
            $resolvedStoreId = $storeIds->first();
        }

        if ($resolvedStoreId === null) {
            $message = $storeIds->isEmpty()
                ? 'No tiene una tienda activa disponible.'
                : 'Debe seleccionar una tienda para continuar.';
            abort(422, $message);
        }

        $request->attributes->set('current_store_id', $resolvedStoreId);

        $memberOrganizationIds = $user->organizations()
            ->wherePivot('status', Organization::STATUS_ACTIVE)
            ->pluck('organizations.id');
        $organizationIds = Organization::whereIn('id', $memberOrganizationIds)
            ->where('is_active', true)->pluck('id');
        $requestedOrganizationId = $request->header('X-Organization-Id');
        $resolvedOrganizationId = null;

        if ($resolvedStoreId !== null) {
            $storeOrganizationId = Store::whereKey($resolvedStoreId)->value('organization_id');

            // Las tiendas sin organización solo pueden existir como datos
            // transitorios/fixtures heredados. Una tienda SaaS real exige
            // además membresía activa en su organización.
            if ($storeOrganizationId !== null) {
                if ($memberOrganizationIds->contains((int) $storeOrganizationId)
                    && ! $organizationIds->contains((int) $storeOrganizationId)) {
                    throw new SubscriptionRestrictionException(
                        'Esta organización está temporalmente suspendida.',
                        'organization_inactive',
                        402
                    );
                }
                if (! $organizationIds->contains((int) $storeOrganizationId)) {
                    throw new AccessDeniedHttpException('No tiene acceso a la organización de esta tienda.');
                }
                $resolvedOrganizationId = (int) $storeOrganizationId;
            }
        } elseif ($requestedOrganizationId !== null && $requestedOrganizationId !== '') {
            if ($memberOrganizationIds->contains((int) $requestedOrganizationId)
                && ! $organizationIds->contains((int) $requestedOrganizationId)) {
                throw new SubscriptionRestrictionException(
                    'Esta organización está temporalmente suspendida.',
                    'organization_inactive',
                    402
                );
            }
            if (! $organizationIds->contains((int) $requestedOrganizationId)) {
                throw new AccessDeniedHttpException('No tiene acceso a esta organización.');
            }
            $resolvedOrganizationId = (int) $requestedOrganizationId;
        } elseif ($organizationIds->count() === 1) {
            $resolvedOrganizationId = (int) $organizationIds->first();
        }

        if ($requestedOrganizationId !== null && $requestedOrganizationId !== ''
            && $resolvedOrganizationId !== null
            && (int) $requestedOrganizationId !== $resolvedOrganizationId) {
            throw new AccessDeniedHttpException('La tienda no pertenece a la organización seleccionada.');
        }

        if ($resolvedOrganizationId !== null) {
            $request->attributes->set('current_organization_id', $resolvedOrganizationId);
        }

        setPermissionsTeamId($resolvedStoreId);

        return $next($request);
    }
}
