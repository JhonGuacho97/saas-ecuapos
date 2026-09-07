<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaaSFreshInstallationTest extends TestCase
{
    use RefreshDatabase;

    public function test_installer_creates_a_global_super_admin_without_tenant_memberships(): void
    {
        // La migración completa de una base vacía no debe fabricar un tenant
        // heredado antes de ejecutar el instalador.
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('stores', 0);
        $this->assertDatabaseCount('roles', 0);

        $this->artisan('saas:install', [
            '--name' => 'Admin Plataforma',
            '--email' => 'platform@example.test',
            '--password' => 'password-segura',
        ])->assertSuccessful();

        $user = User::query()->where('email', 'platform@example.test')->firstOrFail();

        $this->assertTrue($user->is_super_admin);
        $this->assertSame(1, (int) $user->status);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('Admin', $user->first_name);
        $this->assertSame('Plataforma', $user->last_name);
        $this->assertCount(0, $user->organizations);
        $this->assertCount(0, $user->stores);
        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('stores', 0);
    }

    public function test_installer_refuses_to_create_a_second_super_admin(): void
    {
        $existing = User::query()->create([
            'first_name' => 'Super',
            'last_name' => 'Existente',
            'email' => 'existing-super@example.test',
            'password' => bcrypt('password-segura'),
            'language' => 'sp',
        ]);
        $existing->forceFill(['is_super_admin' => true])->save();

        $this->artisan('saas:install', [
            '--name' => 'Otro Admin',
            '--email' => 'other@example.test',
            '--password' => 'password-segura',
        ])->assertSuccessful();

        $this->assertDatabaseMissing('users', ['email' => 'other@example.test']);
    }
}
