<?php

namespace Tests\Feature;

use App\Models\LandingPageSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use DatabaseTransactions;

    public function test_public_landing_uses_ecuapos_defaults_and_active_plans(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Vende, factura y controla tu negocio')
            ->assertSee('/sistema#/crear-cuenta', false)
            ->assertSee('data-auth-user', false)
            ->assertSee('Ir al dashboard')
            ->assertSee('Preguntas frecuentes');
    }

    public function test_only_super_admin_can_manage_landing_content(): void
    {
        Sanctum::actingAs($this->user(false), ['*']);
        $this->getJson('/api/super-admin/landing-page')->assertForbidden();

        $admin = $this->user(true);
        Sanctum::actingAs($admin, ['*']);
        $content = LandingPageSetting::defaults();
        $content['general']['hero_title'] = 'El nuevo mensaje comercial';
        $content['services'] = [];

        $this->putJson('/api/super-admin/landing-page', [
            'content' => $content,
            'is_published' => true,
        ])->assertOk()->assertJsonPath('data.updated_by', $admin->id);

        $this->get('/')->assertOk()->assertSee('El nuevo mensaje comercial');
        $this->assertSame([], LandingPageSetting::firstOrFail()->content['services']);
    }

    public function test_landing_can_verify_a_persisted_application_session(): void
    {
        $user = $this->user(false);
        $token = $user->createToken('landing-session')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/validate-auth-token')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->withToken('invalid-token')
            ->postJson('/api/validate-auth-token')
            ->assertOk()
            ->assertJsonPath('success', false);
    }

    public function test_super_admin_can_upload_public_landing_media(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->user(true), ['*']);

        $response = $this->post('/api/super-admin/landing-page/upload', [
            'section' => 'hero',
            'image' => UploadedFile::fake()->image('hero.webp'),
        ])->assertCreated()->assertJsonPath('success', true);

        Storage::disk('public')->assertExists($response->json('data.path'));
    }

    public function test_unpublished_landing_redirects_to_the_application(): void
    {
        LandingPageSetting::create([
            'content' => LandingPageSetting::defaults(),
            'is_published' => false,
        ]);

        $this->get('/')->assertRedirect('/sistema');
    }

    private function user(bool $superAdmin): User
    {
        return User::create([
            'first_name' => $superAdmin ? 'Super' : 'Usuario',
            'last_name' => 'Landing',
            'email' => uniqid('landing-').'@example.test',
            'phone' => '0999999999',
            'password' => bcrypt('secret123'),
            'language' => 'sp',
            'status' => true,
            'is_super_admin' => $superAdmin,
        ]);
    }
}
