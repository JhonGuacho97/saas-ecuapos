<?php

namespace App\Http\Controllers\API\M1;

use App\Http\Controllers\AppBaseController;
use App\Models\LoginLog;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends AppBaseController
{
    public function login(Request $request): JsonResponse
    {
        $email = $request->get('email');
        $password = $request->get('password');

        if (empty($email) or empty($password)) {
            return $this->sendError('username and password required', 422);
        }
        $user = User::whereRaw('lower(email) = ?', [$email])->first();

        // Antes este canal de login (usado por el m1) no dejaba ningún
        // rastro en LoginLog, a diferencia del AuthController principal --
        // perdiendo trazabilidad forense de intentos fallidos/exitosos
        // que entraran por acá.
        if (empty($user)) {
            LoginLog::create([
                'user_id' => null,
                'email' => $email,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'status' => 'failed',
                'logged_at' => now(),
            ]);

            return $this->sendError('Invalid username or password', 422);
        }

        if (! Hash::check($password, $user->password)) {
            LoginLog::create([
                'user_id' => $user->id,
                'email' => $email,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'status' => 'failed',
                'logged_at' => now(),
            ]);

            return $this->sendError('Invalid username or password', 422);
        }

        LoginLog::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'status' => 'success',
            'logged_at' => now(),
        ]);

        $token = $user->createToken(
            'token',
            ['*'],
            now()->addMinutes((int) config('sanctum.session_token_expiration', 120))
        )->plainTextToken;
        $user->last_name = $user->last_name ?? '';

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $token,
                'user' => $user,
            ],
            'message' => 'Logged in successfully.',
        ]);
    }

    public function logout(): JsonResponse
    {
        //auth()->user()->tokens()->where('id', Auth::user()->currentAccessToken()->id)->delete();
        auth()->user()->tokens()->delete();
        return $this->sendSuccess('Logout Successfully');
    }

    /**
     * @throws ValidationException
     */
    public function sendPasswordResetLinkEmail(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        Password::sendResetLink($request->only('email'));

        // Respuesta idéntica exista o no el correo, para no convertir
        // este endpoint en un oráculo de enumeración de usuarios. Mismo
        // criterio que app/Http/Controllers/API/AuthController.
        return response()->json([
            'success' => true,
            'message' => __('Si el correo está registrado, enviaremos un enlace para restablecer la contraseña.'),
        ], 200);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            // Misma política que la API web y que el alta -- ver
            // app/Http/Controllers/API/AuthController::resetPassword().
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
            return response()->json(['success' => true, 'message' => __($status)], 200);
        } else {
            throw ValidationException::withMessages([
                'email' => __($status),
            ]);
        }
    }
}
