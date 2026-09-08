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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use App\Models\LoginLog;
use App\Services\Security\TotpService;

class AuthController extends AppBaseController
{
    private const MAX_FAILED_LOGIN_ATTEMPTS = 10;

    private const LOGIN_LOCK_MINUTES = 15;

    /**
     * Hash bcrypt de descarte para igualar el tiempo de respuesta del
     * login cuando el correo no existe. No corresponde a ninguna
     * contraseña utilizable: se genera con un valor aleatorio y solo se
     * usa para gastar los mismos ciclos que un Hash::check() real.
     */
    private const DUMMY_HASH = '$2y$10$C6UzMDM.H6dfI/f/IKcEe.7CkFODL0Z3ZQjKQFhZ8dOWo9uCEZqLW';

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
    public function __construct(AuthFactory $auth, private readonly TotpService $totp, int $expiration = null, string $provider = null)
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
            // El mensaje ya es idéntico al de contraseña incorrecta, pero
            // el TIEMPO no lo era: sin usuario no se llegaba a Hash::check()
            // y la respuesta volvía notablemente antes, lo que permite
            // distinguir correos registrados midiendo la latencia. Se paga
            // el mismo costo de bcrypt contra un hash de descarte.
            Hash::check($password, self::DUMMY_HASH);

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

        // El bloqueo se aplica por cuenta, además del throttle por IP. Así
        // también se frena un ataque lento y distribuido. La respuesta sigue
        // siendo la misma que para credenciales incorrectas para no convertir
        // el estado de bloqueo en un mecanismo de enumeración de correos.
        if ($user->locked_until && $user->locked_until->isFuture()) {
            Hash::check($password, self::DUMMY_HASH);
            $this->logFailedLogin($request, $user);

            return $this->sendError(__('messages.error.invalid_username_password'), 422);
        }

        // ❌ Contraseña incorrecta
        if (!Hash::check($password, $user->password)) {
            $this->recordFailedAttempt($user);
            $this->logFailedLogin($request, $user);

            return $this->sendError(__('messages.error.invalid_username_password'), 422);
        }

        if ($user->twoFactorEnabled()) {
            $twoFactorCode = (string) ($request->get('two_factor_code') ?: $request->get('recovery_code'));
            if ($twoFactorCode === '') {
                return response()->json(['data' => [
                    'requires_two_factor' => true,
                    'message' => 'Ingresa el código de tu aplicación de autenticación.',
                ]], 202);
            }

            if (! $this->totp->verifyUserCode($user, $twoFactorCode)) {
                $this->logFailedLogin($request, $user);
                throw ValidationException::withMessages([
                    'two_factor_code' => 'El código de autenticación no es válido.',
                ]);
            }
        }

        if ($user->failed_login_attempts || $user->locked_until) {
            $user->forceFill(['failed_login_attempts' => 0, 'locked_until' => null])->save();
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

        $abilities = $user->is_super_admin && ! $user->twoFactorEnabled()
            ? ['two-factor:setup']
            : ['*'];
        $token = $user->createToken(
            'token',
            $abilities,
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
                'two_factor_setup_required' => (bool) $user->is_super_admin && ! $user->twoFactorEnabled(),
            ],
            'message' => 'Logged in successfully.',
        ]);
    }

    private function recordFailedAttempt(User $user): void
    {
        DB::transaction(function () use ($user) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $attempts = (int) $locked->failed_login_attempts + 1;

            if ($attempts >= self::MAX_FAILED_LOGIN_ATTEMPTS) {
                $locked->forceFill([
                    'failed_login_attempts' => 0,
                    'locked_until' => now()->addMinutes(self::LOGIN_LOCK_MINUTES),
                ])->save();

                return;
            }

            $locked->forceFill(['failed_login_attempts' => $attempts])->save();
        }, 3);
    }

    private function logFailedLogin(Request $request, ?User $user): void
    {
        LoginLog::create([
            'user_id' => $user?->id,
            'email' => $request->get('email'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'status' => 'failed',
            'logged_at' => now(),
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

        Password::sendResetLink($request->only('email'));

        // Respuesta idéntica exista o no el correo. Antes se devolvía
        // "We can't find a user with that e-mail address", que convertía
        // este endpoint en un oráculo para enumerar los usuarios de la
        // plataforma -- justo lo que el login evita usando el mismo
        // mensaje para usuario inexistente y contraseña incorrecta.
        // Tampoco se distingue el caso de throttling del broker por el
        // mismo motivo; el rate limit de la ruta ya frena el abuso.
        return response()->json([
            'message' => __('Si el correo está registrado, enviaremos un enlace para restablecer la contraseña.'),
        ], 200);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            // PasswordRule, no la fachada Password de arriba: esa es el
            // broker de recuperación. Misma política que el alta
            // (CreateOrganizationRequest) -- si acá fuera más laxa, el
            // flujo de "olvidé mi contraseña" sería la puerta para
            // saltarse la política de contraseñas de toda la plataforma.
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
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
