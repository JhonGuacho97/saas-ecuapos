<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use App\Models\LoginLog;

class AuthController extends AppBaseController
{
    /**
     * The authentication factory implementation.
     *
     * @var \Illuminate\Contracts\Auth\Factory
     */
    protected $auth;

    /**
     * The number of minutes tokens should be allowed to remain valid.
     *
     * @var int
     */
    protected $expiration;

    /**
     * The provider name.
     *
     * @var string
     */
    protected $provider;

    /**
     * Create a new guard instance.
     *
     * @return void
     */
    public function __construct(AuthFactory $auth, int $expiration = null, string $provider = null)
    {
        $this->auth = $auth;
        $this->expiration = config('sanctum.expiration');
        $this->provider = $provider;
    }

    /**
     * @return mixed
     */
    public function login(Request $request)
    {
        app()->setLocale($request->language_code);

        $email = $request->get('email');
        $password = $request->get('password');

        if (empty($email) || empty($password)) {
            return $this->sendError('username and password required', 422);
        }

        $user = User::whereRaw('lower(email) = ?', [strtolower($email)])->first();

        // ❌ Usuario no existe
        if (empty($user)) {

            LoginLog::create([
                'user_id' => null,
                'email' => $email,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'status' => 'failed',
                'logged_at' => now(),
            ]);

            return $this->sendError(__('messages.error.invalid_username_password'), 422);
        }

        // ❌ Contraseña incorrecta
        if (!Hash::check($password, $user->password)) {

            LoginLog::create([
                'user_id' => $user->id,
                'email' => $email,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'status' => 'failed',
                'logged_at' => now(),
            ]);

            return $this->sendError(__('messages.error.invalid_username_password'), 422);
        }

        // ✅ Login exitoso
        LoginLog::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'status' => 'success',
            'logged_at' => now(),
        ]);

        // login() corre ANTES de que exista un token/sesión autenticada,
        // así que ResolveActiveStore (que resuelve el team activo para
        // el resto de la app) todavía no corrió para este request -- sin
        // esto, con teams activado, getAllPermissions() filtraría por
        // team_id null y devolvería SIEMPRE vacío, sin importar el rol
        // real del usuario. Ver AppBaseController::allPermissionNamesForUser()
        // para el criterio completo (única tienda / unión de 2+ / ninguna).
        $userPermissions = $user->is_super_admin ? [] : $this->allPermissionNamesForUser($user);

        // Nombre de rol legible para mostrar en el header (ej. "Admin")
        // -- se lee ANTES del unset() de abajo, que borra a propósito
        // $user->roles/permissions del objeto que se serializa completo
        // como 'user' en la respuesta (no queremos mandar los objetos
        // Role/Permission completos al cliente, solo este string).
        $roleName = $user->roles->first()?->name;
        $roleLabel = $roleName ? ucfirst($roleName) : null;

        unset($user->roles);
        unset($user->permissions);

        $token = $user->createToken(
            'token',
            ['*'],
            now()->addMinutes((int) config('sanctum.session_token_expiration', 120))
        )->plainTextToken;

        $user->last_name = $user->last_name ?? '';

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => $user,
                'permissions' => $userPermissions,
                'role_name' => $roleLabel,
                'is_super_admin' => (bool) $user->is_super_admin,
            ],
            'message' => 'Logged in successfully.',
        ]);
    }

    public function logout(): JsonResponse
    {
        auth()->user()->tokens()->where('id', Auth::user()->currentAccessToken()->id)->delete();

        return $this->sendSuccess('Logout Successfully');
    }

    public function sendPasswordResetLinkEmail(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $status = Password::sendResetLink(
            $request->only('email')
        );
        $user = User::whereEmail($request->email)->first();
        if (!$user) {
            return $this->sendError('We can\'t find a user with that e-mail address.');
        }

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json(['message' => __($status)], 200);
        } else {
            throw ValidationException::withMessages([
                'email' => 'Please Wait Before Trying',
            ]);
        }
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:6|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->setRememberToken(Str::random(60));

                $user->save();

                event(new PasswordReset($user));
            }
        );

        if ($status == Password::PASSWORD_RESET) {
            return response()->json(['message' => __($status)], 200);
        } else {
            throw ValidationException::withMessages([
                'email' => __($status),
            ]);
        }
    }

    public function isValidToken(Request $request): JsonResponse
    {
        if ($token = $request->bearerToken()) {
            $model = Sanctum::$personalAccessTokenModel;

            $accessToken = $model::findToken($token);
            $valid = $this->isValidAccessToken($accessToken);

            return response()->json(['success' => __($valid)], 200);
        }
    }

    /**
     * Determine if the provided access token is valid.
     *
     * @param  mixed  $accessToken
     */
    protected function isValidAccessToken($accessToken): bool
    {
        if (!$accessToken) {
            return false;
        }

        $isValid =
            (! $accessToken->expires_at || $accessToken->expires_at->isFuture())
            && $this->hasValidProvider($accessToken->tokenable);

        if (is_callable(Sanctum::$accessTokenAuthenticationCallback)) {
            $isValid = (bool) (Sanctum::$accessTokenAuthenticationCallback)($accessToken, $isValid);
        }

        return $isValid;
    }

    /**
     * Determine if the tokenable model matches the provider's model type.
     */
    protected function hasValidProvider(Model $tokenable): bool
    {
        if (is_null($this->provider)) {
            return true;
        }

        $model = config("auth.providers.{$this->provider}.model");

        return $tokenable instanceof $model;
    }
}
