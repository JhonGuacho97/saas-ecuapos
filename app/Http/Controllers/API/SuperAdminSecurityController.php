<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Services\Security\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class SuperAdminSecurityController extends AppBaseController
{
    public function __construct(private readonly TotpService $totp)
    {
    }

    public function status(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'enabled' => $request->user()->twoFactorEnabled(),
            'confirmed_at' => $request->user()->two_factor_confirmed_at?->toIso8601String(),
        ]]);
    }

    public function setup(Request $request): JsonResponse
    {
        if ($request->user()->twoFactorEnabled()) {
            $data = $request->validate(['password' => ['required', 'string']]);
            if (! Hash::check($data['password'], $request->user()->password)) {
                throw ValidationException::withMessages(['password' => 'La contraseña actual no es válida.']);
            }
        }

        $secret = $this->totp->generateSecret();
        $uri = $this->totp->provisioningUri($secret, $request->user()->email);

        $request->user()->forceFill([
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_used_step' => null,
        ])->save();

        $qrSvg = (string) QrCode::format('svg')->size(220)->margin(1)->generate($uri);

        return response()->json(['data' => [
            'secret' => $secret,
            'qr_data_url' => 'data:image/svg+xml;base64,'.base64_encode($qrSvg),
        ]]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();
        if (! $user->two_factor_secret || ! $this->totp->verifyUserCode($user, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'El código de autenticación no es válido.']);
        }

        $codes = $this->totp->recoveryCodes();
        $this->totp->storeRecoveryCodes($user, $codes);
        $user->two_factor_confirmed_at = now();
        $user->save();

        $token = $user->createToken(
            'token',
            ['*'],
            now()->addMinutes((int) config('sanctum.session_token_expiration', 120))
        )->plainTextToken;

        return response()->json(['data' => [
            'enabled' => true,
            'recovery_codes' => $codes,
            'token' => $token,
        ]]);
    }

    public function disable(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);
        $user = $request->user();
        if (! Hash::check($data['password'], $user->password) || ! $this->totp->verifyUserCode($user, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'La contraseña o el código no son válidos.']);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_used_step' => null,
        ])->save();

        return response()->json(['message' => 'Autenticación de dos factores desactivada.']);
    }
}
