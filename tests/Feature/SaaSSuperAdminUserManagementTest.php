<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\SaaSPlan;
use App\Models\User;
use App\Services\Security\TotpService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SaaSSuperAdminUserManagementTest extends TestCase
{
    use DatabaseTransactions;

    public function test_user_listing_only_contains_users_linked_to_organizations(): void
    {
        $admin = $this->superAdmin();
        $tenantUser = $this->user('Cliente', 'cliente-'.uniqid().'@example.test');
        $orphan = $this->user('Sin organización', 'orphan-'.uniqid().'@example.test');
        $organization = $this->organization('Cliente listado');
        $organization->users()->attach($tenantUser->id, ['role' => Organization::ROLE_OWNER, 'status' => Organization::STATUS_ACTIVE]);
        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/super-admin/users?search='.urlencode($tenantUser->email))->assertOk();

        $response->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $tenantUser->id);
        $this->getJson('/api/super-admin/users?search='.urlencode($admin->email))
            ->assertOk()->assertJsonCount(0, 'data.data');
        $this->getJson('/api/super-admin/users?search='.urlencode($orphan->email))
            ->assertOk()->assertJsonCount(0, 'data.data');
    }

    public function test_super_admin_can_view_organization_and_tenant_user_details_only(): void
    {
        $admin = $this->superAdmin();
        $tenantUser = $this->user('Detalle', 'detail-'.uniqid().'@example.test');
        $orphan = $this->user('Huérfano', 'detail-orphan-'.uniqid().'@example.test');
        $organization = $this->organization('Organización detalle');
        $organization->users()->attach($tenantUser->id, ['role' => Organization::ROLE_MEMBER, 'status' => Organization::STATUS_ACTIVE]);
        Sanctum::actingAs($admin, ['*']);

        $this->getJson("/api/super-admin/organizations/{$organization->id}")
            ->assertOk()->assertJsonPath('data.name', $organization->name)
            ->assertJsonPath('data.users.0.id', $tenantUser->id);
        $this->getJson("/api/super-admin/users/{$tenantUser->id}")
            ->assertOk()->assertJsonPath('data.email', $tenantUser->email)
            ->assertJsonPath('data.organizations.0.id', $organization->id);
        $this->getJson("/api/super-admin/users/{$admin->id}")->assertNotFound();
        $this->getJson("/api/super-admin/users/{$orphan->id}")->assertNotFound();
    }

    public function test_super_admin_can_reset_tenant_password_and_revoke_sessions(): void
    {
        $admin = $this->superAdmin();
        $tenantUser = $this->user('Recuperación', 'recovery-'.uniqid().'@example.test');
        $organization = $this->organization('Organización recuperación');
        $organization->users()->attach($tenantUser->id, ['role' => Organization::ROLE_MEMBER, 'status' => Organization::STATUS_ACTIVE]);
        $tenantUser->createToken('old-session');
        Sanctum::actingAs($admin, ['*']);

        $this->patchJson("/api/super-admin/users/{$tenantUser->id}/password", [
            'password' => 'NuevaClaveSegura123',
            'password_confirmation' => 'NuevaClaveSegura123',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertTrue(Hash::check('NuevaClaveSegura123', $tenantUser->fresh()->password));
        $this->assertSame(0, $tenantUser->tokens()->count());
    }

    public function test_super_admin_password_change_requires_current_password_and_fresh_two_factor_code(): void
    {
        $admin = $this->superAdmin();
        $admin->createToken('other-session');
        Sanctum::actingAs($admin, ['*']);

        $payload = [
            'current_password' => 'ClaveActual123',
            'password' => 'NuevaClaveAdmin123',
            'password_confirmation' => 'NuevaClaveAdmin123',
            'code' => '123',
        ];
        $this->patchJson('/api/super-admin/security/password', $payload)->assertUnprocessable();

        $payload['code'] = app(TotpService::class)->codeAtStep('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', intdiv(time(), 30));
        $this->patchJson('/api/super-admin/security/password', $payload)
            ->assertOk()->assertJsonPath('success', true);

        $this->assertTrue(Hash::check('NuevaClaveAdmin123', $admin->fresh()->password));
        $this->assertSame(0, $admin->tokens()->count());
    }

    public function test_super_admin_can_delete_only_unused_non_system_plans(): void
    {
        $admin = $this->superAdmin();
        Sanctum::actingAs($admin, ['*']);
        $unused = $this->plan('unused-'.uniqid());

        $this->deleteJson("/api/super-admin/plans/{$unused->id}")
            ->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseMissing('saas_plans', ['id' => $unused->id]);

        $assigned = $this->plan('assigned-'.uniqid());
        $organization = $this->organization('Organización con plan');
        OrganizationSubscription::create([
            'organization_id' => $organization->id,
            'saas_plan_id' => $assigned->id,
            'status' => OrganizationSubscription::STATUS_ACTIVE,
            'starts_at' => now(),
            'current_period_starts_at' => now(),
            'current_period_ends_at' => now()->addMonth(),
        ]);

        $this->deleteJson("/api/super-admin/plans/{$assigned->id}")
            ->assertUnprocessable();
        $this->assertDatabaseHas('saas_plans', ['id' => $assigned->id]);

        $trial = SaaSPlan::where('code', 'trial')->first();
        if ($trial) {
            $this->deleteJson("/api/super-admin/plans/{$trial->id}")
                ->assertUnprocessable();
            $this->assertDatabaseHas('saas_plans', ['id' => $trial->id]);
        }
    }

    private function superAdmin(): User
    {
        $user = $this->user('Super Admin', 'super-admin-'.uniqid().'@example.test');
        $user->forceFill([
            'is_super_admin' => true,
            'two_factor_secret' => Crypt::encryptString('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user;
    }

    private function user(string $name, string $email): User
    {
        return User::create([
            'first_name' => $name,
            'last_name' => 'Prueba',
            'email' => $email,
            'phone' => '0999999999',
            'password' => Hash::make('ClaveActual123'),
            'language' => 'sp',
        ]);
    }

    private function organization(string $name): Organization
    {
        return Organization::create([
            'name' => $name,
            'slug' => 'organization-'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function plan(string $code): SaaSPlan
    {
        return SaaSPlan::create([
            'code' => $code,
            'name' => 'Plan de prueba',
            'price' => 10,
            'currency' => 'USD',
            'billing_interval' => 'monthly',
            'billing_interval_count' => 1,
            'trial_days' => 0,
            'grace_days' => 3,
            'features' => ['*'],
            'is_active' => true,
        ]);
    }
}
