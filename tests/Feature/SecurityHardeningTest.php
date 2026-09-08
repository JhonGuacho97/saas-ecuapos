<?php

namespace Tests\Feature;

use App\Http\Controllers\API\SriConfigController;
use App\Models\User;
use App\Services\Security\TotpService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regresiones de seguridad encontradas en la auditoría del 2026-09-08.
 * Ver docs/saas/seguridad.md.
 */
class SecurityHardeningTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * El disco 'local' tiene su root en public_path('uploads'): todo lo que
     * se guarde ahí queda descargable por HTTP sin autenticación. Los
     * certificados .p12 y los RIDE vivían ahí.
     */
    public function test_sri_private_files_are_stored_outside_the_webroot(): void
    {
        $root = Storage::disk(SriConfigController::CERT_DISK)->path('');

        $this->assertStringNotContainsString(
            $this->normalize(public_path()),
            $this->normalize($root),
            'El disco de certificados apunta dentro del docroot: el .p12 quedaría descargable por HTTP.'
        );
    }

    public function test_the_public_uploads_disk_is_still_the_one_meant_for_public_files(): void
    {
        // Contraparte del anterior: si alguien "arregla" el disco 'local'
        // moviéndolo fuera de public/, las imágenes de productos dejan de
        // servirse. Este test documenta que esa NO es la solución.
        $this->assertStringContainsString(
            $this->normalize(public_path()),
            $this->normalize(Storage::disk('local')->path('')),
        );
    }

    /**
     * El alta exige Password::min(8)->letters()->numbers(). Si el reset
     * acepta menos, cualquiera puede bajar su contraseña por "olvidé mi
     * contraseña" y saltarse la política entera.
     */
    public function test_password_reset_cannot_weaken_the_registration_policy(): void
    {
        $user = User::create([
            'first_name' => 'Reset', 'last_name' => 'Policy',
            'email' => 'reset-'.Str::lower(Str::random(10)).'@example.test',
            'phone' => '0999999999', 'password' => bcrypt('ClaveValida123'),
            'language' => 'sp', 'status' => true,
        ]);
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'corta',
            'password_confirmation' => 'corta',
        ])->assertUnprocessable();

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'solamenteletras',
            'password_confirmation' => 'solamenteletras',
        ])->assertUnprocessable();

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'ClaveNueva123',
            'password_confirmation' => 'ClaveNueva123',
        ])->assertOk();
    }

    /**
     * A diferencia del login, el envío del enlace de recuperación
     * distinguía entre correo registrado y no registrado, lo que permitía
     * enumerar usuarios de la plataforma.
     */
    public function test_password_reset_request_does_not_reveal_whether_the_email_exists(): void
    {
        $user = User::create([
            'first_name' => 'Enum', 'last_name' => 'Check',
            'email' => 'enum-'.Str::lower(Str::random(10)).'@example.test',
            'phone' => '0999999999', 'password' => bcrypt('ClaveValida123'),
            'language' => 'sp', 'status' => true,
        ]);

        $existente = $this->postJson('/api/forgot-password', ['email' => $user->email]);
        $inexistente = $this->postJson('/api/forgot-password', [
            'email' => 'no-existe-'.Str::random(12).'@example.test',
        ]);

        $existente->assertOk();
        $inexistente->assertOk();
        $this->assertSame($existente->json('message'), $inexistente->json('message'));
    }

    public function test_a_distributed_password_attack_temporarily_locks_the_account(): void
    {
        $user = User::create([
            'first_name' => 'Lock', 'last_name' => 'Test',
            'email' => 'lock-'.Str::lower(Str::random(10)).'@example.test',
            'phone' => '0999999999', 'password' => bcrypt('ClaveValida123'),
            'language' => 'sp', 'status' => true,
        ]);

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.20.30.{$attempt}"])
                ->postJson('/api/login', [
                    'email' => $user->email,
                    'password' => 'ClaveIncorrecta123',
                    'language_code' => 'sp',
                ])->assertUnprocessable();
        }

        $user->refresh();
        $this->assertNotNull($user->locked_until);
        $this->assertTrue($user->locked_until->isFuture());

        $this->withServerVariables(['REMOTE_ADDR' => '10.20.31.1'])
            ->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'ClaveValida123',
                'language_code' => 'sp',
            ])->assertUnprocessable();
    }

    public function test_super_admin_can_enable_two_factor_and_login_with_a_recovery_code(): void
    {
        $user = User::create([
            'first_name' => 'Super', 'last_name' => 'Seguro',
            'email' => 'two-factor-'.Str::lower(Str::random(10)).'@example.test',
            'phone' => '0999999999', 'password' => bcrypt('ClaveValida123'),
            'language' => 'sp', 'status' => true,
        ]);
        $user->forceFill(['is_super_admin' => true])->save();
        Sanctum::actingAs($user, ['*']);

        $setup = $this->postJson('/api/super-admin/security/two-factor/setup')->assertOk();
        $secret = $setup->json('data.secret');
        $this->assertNotEmpty($setup->json('data.qr_data_url'));

        $code = app(TotpService::class)->codeAtStep($secret, intdiv(time(), 30));
        $confirmation = $this->postJson('/api/super-admin/security/two-factor/confirm', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('data.enabled', true);
        $recoveryCode = $confirmation->json('data.recovery_codes.0');

        $user->refresh();
        $this->assertTrue($user->twoFactorEnabled());
        $this->assertNotSame($secret, $user->getRawOriginal('two_factor_secret'));

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'ClaveValida123',
            'language_code' => 'sp',
        ])->assertStatus(202)->assertJsonPath('data.requires_two_factor', true);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'ClaveValida123',
            'two_factor_code' => $recoveryCode,
            'language_code' => 'sp',
        ])->assertOk()->assertJsonPath('data.is_super_admin', true);
    }

    public function test_unenrolled_super_admin_token_can_only_access_two_factor_setup(): void
    {
        $user = User::create([
            'first_name' => 'Pending', 'last_name' => 'TwoFactor',
            'email' => 'pending-2fa-'.Str::lower(Str::random(10)).'@example.test',
            'phone' => '0999999999', 'password' => bcrypt('ClaveValida123'),
            'language' => 'sp', 'status' => true,
        ]);
        $user->forceFill(['is_super_admin' => true])->save();

        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'ClaveValida123',
            'language_code' => 'sp',
        ])->assertOk()->assertJsonPath('data.two_factor_setup_required', true);
        $token = $login->json('data.token');

        $this->withToken($token)->getJson('/api/super-admin/security/two-factor')->assertOk();
        $this->withToken($token)->getJson('/api/super-admin/dashboard')
            ->assertForbidden()
            ->assertJsonPath('restriction', 'two_factor_setup_required');
    }

    public function test_unenrolled_super_admin_is_blocked_even_with_a_legacy_full_access_token(): void
    {
        $user = User::create([
            'first_name' => 'Legacy', 'last_name' => 'Admin',
            'email' => 'legacy-2fa-'.Str::lower(Str::random(10)).'@example.test',
            'phone' => '0999999999', 'password' => bcrypt('ClaveValida123'),
            'language' => 'sp', 'status' => true,
        ]);
        $user->forceFill(['is_super_admin' => true])->save();
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/super-admin/security/two-factor')->assertOk();
        $this->getJson('/api/super-admin/dashboard')
            ->assertForbidden()
            ->assertJsonPath('restriction', 'two_factor_setup_required');
    }

    private function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
