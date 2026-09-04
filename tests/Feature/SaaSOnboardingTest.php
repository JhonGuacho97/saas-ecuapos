<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaaSOnboardingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_create_a_complete_operational_tenant(): void
    {
        config(['saas.self_registration_enabled' => true]);
        $beforeOrganizations = Organization::count();
        $email = 'owner-'.Str::lower(Str::random(10)).'@example.test';

        $response = $this->postJson('/api/onboarding/register', [
            'organization_name' => 'Café del Pacífico',
            'store_name' => 'Matriz Manta',
            'warehouse_name' => 'Bodega matriz',
            'first_name' => 'María',
            'last_name' => 'Zambrano',
            'email' => $email,
            'phone' => '0991234567',
            'city' => 'Manta',
            'province' => 'Manabí',
            'password' => 'ClaveSaaS123',
            'password_confirmation' => 'ClaveSaaS123',
            'terms' => true,
        ])->assertCreated();

        $organizationId = (int) $response->json('data.organization.id');
        $storeId = (int) $response->json('data.store.id');
        $warehouseId = (int) $response->json('data.warehouse.id');
        $userId = (int) $response->json('data.user.id');

        $this->assertSame($beforeOrganizations + 1, Organization::count());
        $this->assertDatabaseHas('stores', [
            'id' => $storeId,
            'organization_id' => $organizationId,
            'is_default' => true,
        ]);
        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'role' => 'OWNER',
            'status' => 'ACTIVE',
        ]);
        $this->assertDatabaseHas('user_store', ['user_id' => $userId, 'store_id' => $storeId]);
        $this->assertDatabaseHas('warehouses', ['id' => $warehouseId, 'store_id' => $storeId]);
        $this->assertDatabaseHas('cash_registers', [
            'store_id' => $storeId,
            'warehouse_id' => $warehouseId,
            'code' => 'CAJA-01',
        ]);
        $this->assertDatabaseHas('customers', [
            'store_id' => $storeId,
            'identification' => '9999999999999',
            'es_consumidor_final' => true,
        ]);
        $this->assertDatabaseHas('settings', [
            'store_id' => $storeId,
            'key' => 'company_name',
            'value' => 'Café del Pacífico',
        ]);
        $this->assertDatabaseHas('settings', [
            'store_id' => $storeId,
            'key' => 'logo',
            'value' => 'images/ecua-pos-logo.png',
        ]);
        $this->assertDatabaseHas('organization_subscriptions', [
            'organization_id' => $organizationId,
            'status' => 'TRIALING',
            'electronic_documents_used' => 0,
        ]);
        $this->assertSame('trial', $response->json('data.subscription.plan.code'));
        $this->assertSame(14, $response->json('data.subscription.days_remaining'));
        $this->assertSame(1, $response->json('data.subscription.limits.users'));
        $this->assertSame(10, $response->json('data.subscription.limits.electronic_documents'));

        setPermissionsTeamId($storeId);
        $role = Role::where('store_id', $storeId)->where('name', 'admin')->firstOrFail();
        $this->assertSame(Permission::where('guard_name', 'web')->count(), $role->permissions()->count());
        $this->assertTrue(User::findOrFail($userId)->hasRole($role));

        $this->postJson('/api/login', [
            'email' => $email,
            'password' => 'ClaveSaaS123',
            'language_code' => 'sp',
        ])->assertOk()->assertJsonPath('data.user.email', $email);
    }

    public function test_duplicate_owner_email_does_not_create_partial_tenant(): void
    {
        config(['saas.self_registration_enabled' => true]);
        $existing = User::firstOrFail();
        $beforeOrganizations = Organization::count();
        $beforeStores = Store::count();

        $this->postJson('/api/onboarding/register', [
            'organization_name' => 'No debe persistir',
            'first_name' => 'Cuenta',
            'last_name' => 'Duplicada',
            'email' => $existing->email,
            'phone' => '0991234567',
            'city' => 'Manta',
            'password' => 'ClaveSaaS123',
            'password_confirmation' => 'ClaveSaaS123',
            'terms' => true,
        ])->assertUnprocessable();

        $this->assertSame($beforeOrganizations, Organization::count());
        $this->assertSame($beforeStores, Store::count());
    }
}
