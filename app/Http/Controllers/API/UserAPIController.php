<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Http\Requests\AdminUpdateUserPasswordRequest;
use App\Http\Requests\CreateUserRequest;
use App\Http\Requests\UpdateChangePasswordRequest;
use App\Http\Requests\UpdateUserProfileRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserCollection;
use App\Http\Resources\UserResource;
use App\Models\POSRegister;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\SaaS\EntitlementService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Class UserAPIController
 */
class UserAPIController extends AppBaseController
{
    /** @var UserRepository */
    private $userRepository;

    public function __construct(
        UserRepository $userRepository,
        private readonly EntitlementService $entitlements
    )
    {
        $this->userRepository = $userRepository;
    }

    public function index(Request $request): UserCollection
    {
        $perPage = getPageSize($request);
        $users = $this->userRepository->getUsers($perPage);
        UserResource::usingWithCollection();

        return new UserCollection($users);
    }

    public function store(CreateUserRequest $request): UserResource
    {
        $input = $request->all();
        $user = $this->entitlements->withinResourceLimit(
            $this->requireCurrentOrganizationId(),
            EntitlementService::RESOURCE_USERS,
            fn () => $this->userRepository->storeUser($input)
        );

        return new UserResource($user);
    }

    public function show($id): UserResource
    {
        $user = $this->userRepository->find($id);

        return new UserResource($user);
    }

    /**
     * @return UserResource|JsonResponse
     */
    public function update(UpdateUserRequest $request, User $user)
    {
        if (Auth::id() == $user->id) {
            return $this->sendError('User can\'t be updated.');
        }
        $input = $request->all();
        $user = $this->userRepository->updateUser($input, $user->id);

        return new UserResource($user);
    }

    public function destroy(User $user): JsonResponse
    {
        if (Auth::id() == $user->id) {
            return $this->sendError('User can\'t be deleted.');
        }
        $this->userRepository->delete($user->id);

        return $this->sendSuccess('User deleted successfully');
    }

    public function editProfile(): UserResource
    {
        $user = Auth::user();

        return new UserResource($user);
    }

    public function updateProfile(UpdateUserProfileRequest $request): UserResource
    {
        $input = $request->all();
        $updateUser = $this->userRepository->updateUserProfile($input);

        return new UserResource($updateUser);
    }

    public function changePassword(UpdateChangePasswordRequest $request): JsonResponse
    {
        $input = $request->all();
        try {
            $this->userRepository->updatePassword($input);

            return $this->sendSuccess('Password updated successfully');
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }

    public function updateLanguage(Request $request): JsonResponse
    {
        $language = $request->get('language');
        $user = Auth::user();
        $user->update([
            'language' => $language,
        ]);

        return $this->sendResponse($user->language, 'Language Updated Successfully');
    }

    public function updateUserPassword(AdminUpdateUserPasswordRequest $request, User $user): JsonResponse
    {
        // Solo un admin puede resetear la contraseña de otro admin --
        // evita que un usuario con permiso manage_users (pero sin ser
        // admin) tome control de una cuenta admin restableciendo su clave.
        if ($user->isUnrestrictedAdmin() && !Auth::user()->isUnrestrictedAdmin()) {
            throw new AccessDeniedHttpException('No tiene permiso para cambiar la contraseña de este usuario.');
        }

        $this->userRepository->updateUserPassword($user->id, $request->password);

        return $this->sendSuccess('Contraseña Actualizada Correctamente');
    }

    public function config(Request $request)
    {
        $user = Auth::user();

        // Mismo hueco que había en AuthController::login(): con 2+
        // tiendas y sin X-Store-Id resuelto todavía (típico en el
        // primer render tras loguearse, antes de elegir tienda),
        // getAllPermissions() sin este helper devuelve SIEMPRE vacío --
        // y como este es justo el endpoint que arma el menú lateral
        // (ver App.js -> fetchConfig()), eso deja al usuario con la
        // pantalla en blanco sin ninguna forma de llegar al selector de
        // tienda para elegir una. Ver AppBaseController::allPermissionNamesForUser().
        $userPermissions = $this->allPermissionNamesForUser($user);

        $composerFile = file_get_contents('../composer.json');
        $composerData = json_decode($composerFile, true);
        $currentVersion = isset($composerData['version']) ? $composerData['version'] : '';
        $dateFormat = getSettingValue('date_format');

        $openRegister = POSRegister::where('user_id', Auth::id())
            ->whereNull('closed_at')
            ->exists();

        return $this->sendResponse([
            'permissions' => $userPermissions,
            'version' => $currentVersion,
            'date_format' => $dateFormat,
            'is_version' => getSettingValue('show_version_on_footer'),
            'is_currency_right' => getSettingValue('is_currency_right'),
            'open_register' => $openRegister ? false : true,
            // Admin siempre entra por el almacén global de Ajustes, con
            // acceso a todos -- el almacén por defecto de usuario es solo
            // para el resto de roles (vendedores, etc.).
            'default_warehouse_id' => $user->isUnrestrictedAdmin()
                ? null
                : $user->default_warehouse_id,
            'default_warehouse_name' => $user->isUnrestrictedAdmin()
                ? null
                : $user->defaultWarehouse?->name,
            'subscription' => $this->entitlements->summary($this->requireCurrentOrganizationId()),
        ], 'Config retrieved successfully.');
    }
}
