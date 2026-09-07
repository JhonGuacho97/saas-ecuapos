<?php

namespace App\Repositories;

use App\Models\Role;
use App\Models\User;
use App\Models\Language;
use App\Models\Organization;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Class UserRepository
 */
class UserRepository extends BaseRepository
{
    /**
     * @var array
     */
    protected $fieldSearchable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'created_at',
        //        'roles.name',
    ];

    /**
     * Return searchable fields
     */
    public function getFieldsSearchable(): array
    {
        return $this->fieldSearchable;
    }

    /**
     * Configure the Model
     **/
    public function model()
    {
        return User::class;
    }

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator|\Illuminate\Support\Collection|mixed
     */
    public function storeUser($input)
    {
        try {
            DB::beginTransaction();
            unset($input['is_super_admin']);
            $input['password'] = Hash::make($input['password']);
            // Un select vacío llega como '' desde el formulario -- en la
            // columna (entero, nullable) eso debe guardarse como NULL, no
            // como texto vacío.
            if (isset($input['default_warehouse_id']) && $input['default_warehouse_id'] === '') {
                $input['default_warehouse_id'] = null;
            }
            // El formulario de usuarios no solicita idioma. Antes se omitía
            // este campo y MySQL aplicaba el antiguo DEFAULT 'en', aunque la
            // tienda tuviera Español como idioma predeterminado.
            if (empty($input['language'])) {
                $input['language'] = Language::whereKey(getSettingValue('default_language'))
                    ->value('iso_code') ?: 'sp';
            }
            $storeIds = $this->resolveGrantableStoreIds($input['store_ids'] ?? []);
            $user = $this->create($input);
            $user->organizations()->syncWithoutDetaching([
                requireCurrentOrganizationId() => [
                    'role' => Organization::ROLE_MEMBER,
                    'status' => Organization::STATUS_ACTIVE,
                ],
            ]);
            $user->stores()->sync($storeIds);
            if (isset($input['role_id'])) {
                if (!Auth::user() || !Auth::user()->isUnrestrictedAdmin()) {
                    throw new UnprocessableEntityHttpException('No tiene permiso para asignar roles.');
                }
                $this->assignRoleAcrossStores($user, (int) $input['role_id'], $storeIds);
            }
            if (isset($input['image']) && !empty($input['image'])) {
                $user->addMedia($input['image'])->toMediaCollection(
                    User::PATH,
                    config('app.media_disc')
                );
            }
            DB::commit();

            return $user;
        } catch (Exception $e) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }

    /**
     * Un admin solo puede dar acceso a tiendas a las que ÉL MISMO tiene
     * acceso -- no puede otorgar una tienda ajena que ni siquiera ve.
     * Si el formulario no marcó ninguna (o vino vacío), cae de nuevo a
     * "la tienda activa" para no dejar al usuario sin ninguna (mismo
     * comportamiento de antes de este cambio).
     */
    private function resolveGrantableStoreIds(array $requestedStoreIds): array
    {
        $grantableQuery = Auth::user()->stores()->where('stores.is_active', true);
        if ($organizationId = currentOrganizationId()) {
            $grantableQuery->where('stores.organization_id', $organizationId);
        } else {
            // Compatibilidad con instalaciones heredadas sin organización:
            // nunca ampliar el alcance más allá de la tienda activa.
            $grantableQuery->whereKey(requireCurrentStoreId());
        }
        $grantable = $grantableQuery->pluck('stores.id')->all();
        $storeIds = array_values(array_intersect(array_map('intval', $requestedStoreIds), $grantable));

        if (count(array_unique(array_map('intval', $requestedStoreIds))) !== count($storeIds)) {
            throw new UnprocessableEntityHttpException('No puede asignar usuarios a tiendas de otra organización.');
        }

        return $storeIds ?: [requireCurrentStoreId()];
    }

    /**
     * Asigna el ROL (por nombre, no por ID) al usuario en cada tienda de
     * $storeIds -- los roles son por tienda (modo teams de Spatie), así
     * que "el mismo rol" en otra tienda es, en la BD, una fila de Role
     * distinta con el mismo name. Si todavía no existe ahí, se crea
     * clonando los permisos del rol de origen. $sourceRoleId es el id
     * del rol elegido en el dropdown, que siempre pertenece a la tienda
     * activa del admin que está creando/editando (ver RoleAPIController
     * ::index(), ya filtrado por currentStoreId()).
     */
    private function assignRoleAcrossStores(User $user, int $sourceRoleId, array $storeIds): void
    {
        $sourceRole = Role::with('permissions')->findOrFail($sourceRoleId);
        $originalTeamId = getPermissionsTeamId();

        try {
            foreach ($storeIds as $storeId) {
                setPermissionsTeamId($storeId);

                $targetRole = Role::where('name', $sourceRole->name)
                    ->where('store_id', $storeId)
                    ->first();

                if (!$targetRole) {
                    $targetRole = Role::create([
                        'name' => $sourceRole->name,
                        'display_name' => $sourceRole->display_name,
                        'guard_name' => $sourceRole->guard_name,
                    ]);
                    $targetRole->syncPermissions($sourceRole->permissions);
                }

                // syncRoles() y no assignRole(): el usuario puede llegar acá
                // con OTRO rol ya asignado en esta misma tienda (ej. cambiar
                // de SUPER_ADMIN a admin) -- assignRole() solo AGREGA, deja
                // el rol viejo pegado (quedaba "adminSUPER_ADMIN" concatenado
                // en pantalla y dos filas en model_has_roles). syncRoles()
                // reemplaza, y es seguro en modo teams porque roles() ya
                // viene wherePivot(store_id, tienda activa) -- el detach()
                // interno de syncRoles() solo toca esta tienda, no las demás.
                $user->syncRoles($targetRole);
            }
        } finally {
            setPermissionsTeamId($originalTeamId);
        }
    }

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator|\Illuminate\Support\Collection|mixed
     */
    public function updateUser($input, $id)
    {
        try {
            DB::beginTransaction();
            unset($input['is_super_admin']);
            if (isset($input['default_warehouse_id']) && $input['default_warehouse_id'] === '') {
                $input['default_warehouse_id'] = null;
            }
            $user = $this->update($input, $id);

            if (isset($input['store_ids'])) {
                $storeIds = $this->resolveGrantableStoreIds($input['store_ids']);
                // Tiendas que el usuario tenía y ya no -- se le sueltan
                // también sus roles ahí (ver assignRoleAcrossStores());
                // dejarlos vivos sería un permiso fantasma sin forma de
                // usarse (ResolveActiveStore ya no dejaría elegir esa
                // tienda), pero mejor no dejar basura en model_has_roles.
                $removedStoreIds = $user->stores()->pluck('stores.id')->diff($storeIds);
                $user->stores()->sync($storeIds);

                if ($removedStoreIds->isNotEmpty()) {
                    // Delete directo a la tabla pivot en vez de
                    // ->roles()->wherePivot(...)->detach() -- detach()
                    // sin IDs explícitos ignora los where() encadenados y
                    // borraría TODAS las filas de rol del usuario, no
                    // solo las de la tienda que se le quitó.
                    DB::table('model_has_roles')
                        ->where('model_id', $user->id)
                        ->where('model_type', $user->getMorphClass())
                        ->whereIn('store_id', $removedStoreIds)
                        ->delete();
                }

                if (isset($input['role_id'])) {
                    if (!Auth::user() || !Auth::user()->isUnrestrictedAdmin()) {
                        throw new UnprocessableEntityHttpException('No tiene permiso para asignar roles.');
                    }
                    $this->assignRoleAcrossStores($user, (int) $input['role_id'], $storeIds);
                }
            } elseif (isset($input['role_id'])) {
                if (!Auth::user() || !Auth::user()->isUnrestrictedAdmin()) {
                    throw new UnprocessableEntityHttpException('No tiene permiso para asignar roles.');
                }
                $user->syncRoles($input['role_id']);
            }
            if (isset($input['image']) && $input['image']) {
                $user->clearMediaCollection(User::PATH);
                $user['image_url'] = $user->addMedia($input['image'])->toMediaCollection(
                    User::PATH,
                    config('app.media_disc')
                );
            }
            DB::commit();

            return $user;
        } catch (Exception $e) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }

    /**
     * @return User|\Illuminate\Contracts\Auth\Authenticatable|null
     */
    public function updateUserProfile($input)
    {
        try {
            DB::beginTransaction();
            unset($input['role_id']);
            unset($input['is_super_admin']);

            $user = Auth::user();
            $user->update($input);

            if ((!empty($input['image']))) {
                $user->clearMediaCollection(User::PATH);
                $user->media()->delete();
                $user->addMedia($input['image'])->toMediaCollection(User::PATH, config('app.media_disc'));
            }
            DB::commit();

            return $user;
        } catch (\Exception $e) {
            DB::rollBack();

            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator|\Illuminate\Support\Collection|mixed
     */
    public function getUsers($perPage)
    {
        $loginUserId = Auth::id();
        $storeId = requireCurrentStoreId();
        $organizationId = requireCurrentOrganizationId();

        // El módulo tenant nunca debe listar superadministradores de la
        // plataforma. Además exigimos simultáneamente membresía activa en
        // la organización y acceso a la tienda actual; una asociación
        // heredada o accidental en user_store ya no basta para mezclar
        // cuentas de otra organización.
        $users = $this->where('is_super_admin', false)
            ->whereHas('organizations', function ($query) use ($organizationId) {
                $query->where('organizations.id', $organizationId)
                    ->where('organization_user.status', Organization::STATUS_ACTIVE);
            })
            ->whereHas('stores', function ($query) use ($storeId) {
                $query->where('stores.id', $storeId);
            });

        if (! Auth::user()->isUnrestrictedAdmin()) {
            // No solo el rol llamado 'admin': cualquier rol que hoy tenga
            // TODOS los permisos del sistema (ver Role::unrestrictedRoleIds())
            // queda oculto de la lista igual que 'admin' siempre lo estuvo.
            $unrestrictedRoleIds = Role::unrestrictedRoleIds();
            $users = $users->whereHas('roles', function ($q) use ($unrestrictedRoleIds, $storeId) {
                $q->where('roles.store_id', $storeId)
                    ->whereNotIn('roles.id', $unrestrictedRoleIds);
            });
        }

        if (request()->get('returnAll') == 'true') {
            $users = $users->paginate($perPage);
        } else {
            $users = $users->where('id', '!=', $loginUserId)->paginate($perPage);
        }

        return $users;
    }

    public function updateUserPassword($userId, $password)
    {
        $user = User::findOrFail($userId);

        $user->update([
            'password' => Hash::make($password),
        ]);

        return $user;
    }

    public function updatePassword(array $input): User
    {
        /** @var User $user */
        $user = Auth::user();
        if (!Hash::check($input['current_password'], $user->password)) {
            throw new UnprocessableEntityHttpException('Current password is invalid.');
        }
        $input['password'] = Hash::make($input['new_password']);
        $user->update($input);

        return $user;
    }
}
