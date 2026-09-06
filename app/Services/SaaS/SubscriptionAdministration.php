<?php

namespace App\Services\SaaS;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\User;

/**
 * Quién puede ver y mover la suscripción de una organización (renovar,
 * mejorar de plan, enviar comprobantes).
 *
 * La regla del negocio es "un rol con todos los permisos de la
 * organización", que es exactamente el rol Administrador que crea el
 * onboarding. Se acepta además al dueño/admin de la cuenta por pivote:
 * es quien contrató el servicio, y si algún día pierde un permiso suelto
 * no puede quedarse sin forma de pagar -- eso lo dejaría fuera del
 * producto sin manera de volver a entrar.
 */
class SubscriptionAdministration
{
    public function canManage(User $user, Organization $organization): bool
    {
        $membership = $organization->users()
            ->wherePivot('status', Organization::STATUS_ACTIVE)
            ->where('users.id', $user->id)
            ->first();

        if (! $membership) {
            return false;
        }

        if (in_array($membership->pivot->role, [Organization::ROLE_OWNER, Organization::ROLE_ADMIN], true)) {
            return true;
        }

        $previousTeamId = getPermissionsTeamId();

        try {
            $storeIds = $user->stores()
                ->where('stores.organization_id', $organization->id)
                ->where('stores.is_active', true)
                ->pluck('stores.id');

            foreach ($storeIds as $storeId) {
                setPermissionsTeamId($storeId);
                $user->unsetRelation('roles')->unsetRelation('permissions');
                if ($this->hasEveryPermission($user->getAllPermissions()->pluck('name')->all())) {
                    return true;
                }
            }

            return false;
        } finally {
            setPermissionsTeamId($previousTeamId);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    /**
     * @param  array<int, string>  $permissionNames
     */
    public function hasEveryPermission(array $permissionNames): bool
    {
        $required = Permission::where('guard_name', 'web')->pluck('name')->all();
        $granted = array_values(array_unique($permissionNames));

        return $required !== [] && array_diff($required, $granted) === [];
    }
}
