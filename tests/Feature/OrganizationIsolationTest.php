<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\POSRegister;
use App\Models\SaaSPlan;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Setting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class OrganizationIsolationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_store_context_resolves_its_organization(): void
    {
        [$organization, $store, $user] = $this->context();
        Sanctum::actingAs($user, ['*']);

        $this->withHeader('X-Store-Id', $store->id)
            ->getJson('/api/current-organization')
            ->assertOk()
            ->assertJsonPath('data.id', $organization->id)
            ->assertJsonPath('data.name', $organization->name);
    }

    public function test_store_membership_cannot_bypass_organization_membership(): void
    {
        [, , $user] = $this->context();
        $foreignOrganization = $this->organization('Ajena');
        $foreignStore = $this->store($foreignOrganization, 'Tienda ajena');

        // Incluso ante una fila user_store incoherente, la frontera de la
        // organización prevalece y bloquea la petición.
        $user->stores()->attach($foreignStore->id);
        Sanctum::actingAs($user, ['*']);

        $this->withHeader('X-Store-Id', $foreignStore->id)
            ->getJson('/api/current-organization')
            ->assertUnprocessable();
    }

    public function test_organization_header_must_match_selected_store(): void
    {
        [$organization, $store, $user] = $this->context();
        $other = $this->organization('Alterna');
        $user->organizations()->attach($other->id, ['role' => 'MEMBER', 'status' => 'ACTIVE']);
        Sanctum::actingAs($user, ['*']);

        $this->withHeaders([
            'X-Store-Id' => $store->id,
            'X-Organization-Id' => $other->id,
        ])->getJson('/api/current-organization')->assertUnprocessable();

        $this->assertNotSame($organization->id, $other->id);
    }

    public function test_new_store_is_always_attached_to_current_organization(): void
    {
        [$organization, $store, $user] = $this->context();
        setPermissionsTeamId($store->id);
        $permission = Permission::firstOrCreate(['name' => 'manage_stores', 'guard_name' => 'web']);
        $user->givePermissionTo($permission);
        Sanctum::actingAs($user, ['*']);

        $name = 'Sucursal '.Str::random(10);
        $response = $this->withHeader('X-Store-Id', $store->id)
            ->postJson('/api/stores', ['name' => $name, 'is_active' => true])
            ->assertSuccessful();

        $createdId = (int) $response->json('data.id');
        $this->assertDatabaseHas('stores', [
            'id' => $createdId,
            'organization_id' => $organization->id,
            'name' => $name,
        ]);
    }

    public function test_store_administration_never_lists_another_organization(): void
    {
        [$organization, $store, $user] = $this->context();
        $foreignStore = $this->store($this->organization('Ajena'), 'Sucursal ajena');
        setPermissionsTeamId($store->id);
        $permission = Permission::firstOrCreate(['name' => 'manage_stores', 'guard_name' => 'web']);
        $user->givePermissionTo($permission);
        Sanctum::actingAs($user, ['*']);

        $response = $this->withHeader('X-Store-Id', $store->id)
            ->getJson('/api/stores?page[size]=100')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id);
        $this->assertTrue($ids->contains($store->id));
        $this->assertFalse($ids->contains($foreignStore->id));
        $this->assertSame($organization->id, $store->organization_id);
    }

    public function test_pos_defaults_follow_active_store_even_when_user_default_is_in_another_store(): void
    {
        [$organization, $store, $user] = $this->context();
        $otherStore = $this->store($organization, 'Sucursal');
        $user->stores()->attach($otherStore->id);
        $first = $this->warehouse($store);
        $second = $this->warehouse($otherStore);
        $user->update(['default_warehouse_id' => $first->id]);
        foreach ([$store, $otherStore] as $allowedStore) {
            setPermissionsTeamId($allowedStore->id);
            $user->unsetRelation('permissions')->unsetRelation('roles');
            $user->givePermissionTo(Permission::all());
        }
        // Simula configuraciones heredadas que apuntan a otra tienda.
        Setting::updateOrCreate(['store_id' => $otherStore->id, 'key' => 'default_warehouse'], ['value' => $first->id]);
        Sanctum::actingAs($user, ['*']);

        foreach ([[$store, $first], [$otherStore, $second], [$store, $first]] as [$activeStore, $expected]) {
            $user->unsetRelation('permissions')->unsetRelation('roles');
            $this->withHeader('X-Store-Id', $activeStore->id)->getJson('/api/config')
                ->assertOk()->assertJsonPath('data.default_warehouse_id', $expected->id);
            $this->getJson('/api/settings')->assertOk()
                ->assertJsonPath('data.attributes.default_warehouse', $expected->id)
                ->assertJsonPath('data.attributes.warehouse_name', $expected->name);
            $this->getJson('/api/available-cash-registers')->assertOk();
        }

        $user->unsetRelation('permissions')->unsetRelation('roles');
        $this->withHeader('X-Store-Id', $otherStore->id)
            ->postJson('/api/register-entry', ['cash_in_hand' => 0])->assertOk();
        $this->assertDatabaseHas('pos_register', ['user_id' => $user->id, 'warehouse_id' => $second->id]);
        $this->assertDatabaseMissing('pos_register', ['user_id' => $user->id, 'warehouse_id' => $first->id]);
    }

    public function test_open_cash_sessions_are_isolated_when_user_switches_stores(): void
    {
        [$organization, $firstStore, $user] = $this->context();
        $secondStore = $this->store($organization, 'Sucursal sin actividad');
        $user->stores()->attach($secondStore->id);
        $firstWarehouse = $this->warehouse($firstStore);
        $secondWarehouse = $this->warehouse($secondStore);
        foreach ([$firstStore, $secondStore] as $allowedStore) {
            setPermissionsTeamId($allowedStore->id);
            $user->unsetRelation('permissions')->unsetRelation('roles');
            $user->givePermissionTo(Permission::all());
        }
        Sanctum::actingAs($user, ['*']);

        $this->withHeader('X-Store-Id', $firstStore->id)
            ->postJson('/api/register-entry', ['cash_in_hand' => 25])
            ->assertOk();

        $firstSession = POSRegister::where('user_id', $user->id)
            ->where('warehouse_id', $firstWarehouse->id)->whereNull('closed_at')->firstOrFail();

        // Cambiar de tienda no puede reutilizar ni mostrar el turno anterior.
        $this->withHeader('X-Store-Id', $secondStore->id)
            ->getJson('/api/config')->assertOk()->assertJsonPath('data.open_register', true);
        $this->withHeader('X-Store-Id', $secondStore->id)
            ->getJson('/api/get-register-details')->assertUnprocessable();

        $this->withHeader('X-Store-Id', $secondStore->id)
            ->postJson('/api/register-entry', ['cash_in_hand' => 0])
            ->assertOk();
        $secondSession = POSRegister::where('user_id', $user->id)
            ->where('warehouse_id', $secondWarehouse->id)->whereNull('closed_at')->firstOrFail();

        $this->assertNotSame($firstSession->id, $secondSession->id);
        $this->assertSame(2, POSRegister::where('user_id', $user->id)->whereNull('closed_at')->count());

        $this->withHeader('X-Store-Id', $secondStore->id)
            ->postJson('/api/register-close', ['cash_in_hand_while_closing' => 0])
            ->assertOk();

        $this->assertNull($firstSession->fresh()->closed_at);
        $this->assertNotNull($secondSession->fresh()->closed_at);
    }

    public function test_new_store_does_not_inherit_entity_ids_from_global_settings(): void
    {
        [, $store, $user] = $this->context();
        $foreignStore = $this->store($this->organization('Otra empresa'), 'Ajena');
        $foreignWarehouse = $this->warehouse($foreignStore);
        Setting::updateOrCreate(['store_id' => null, 'key' => 'default_warehouse'], ['value' => $foreignWarehouse->id]);
        Sanctum::actingAs($user, ['*']);

        $this->withHeader('X-Store-Id', $store->id)->getJson('/api/settings')->assertOk()
            ->assertJsonPath('data.attributes.default_warehouse', null)
            ->assertJsonPath('data.attributes.default_customer', null);
        $this->getJson('/api/available-cash-registers')->assertUnprocessable();
        $this->getJson('/api/available-cash-registers?warehouse_id='.$foreignWarehouse->id)->assertUnprocessable();
    }

    public function test_inactive_store_default_is_replaced_with_an_active_local_warehouse(): void
    {
        [, $store, $user] = $this->context();
        $inactive = $this->warehouse($store);
        $inactive->update(['is_active' => false]);
        $active = $this->warehouse($store);
        Setting::updateOrCreate(['store_id' => $store->id, 'key' => 'default_warehouse'], ['value' => $inactive->id]);
        Sanctum::actingAs($user, ['*']);

        $this->withHeader('X-Store-Id', $store->id)->getJson('/api/config')->assertOk()
            ->assertJsonPath('data.default_warehouse_id', $active->id);
        $this->getJson('/api/settings')->assertOk()
            ->assertJsonPath('data.attributes.default_warehouse', $active->id);
    }

    public function test_pos_preserves_seller_warehouse_restrictions(): void
    {
        [, $store, $user] = $this->context();
        $first = $this->warehouse($store);
        $assigned = $this->warehouse($store);
        $user->update(['default_warehouse_id' => $assigned->id]);
        Setting::updateOrCreate(['store_id' => $store->id, 'key' => 'default_warehouse'], ['value' => $first->id]);
        Sanctum::actingAs($user, ['*']);

        $this->withHeader('X-Store-Id', $store->id)->getJson('/api/config')->assertOk()
            ->assertJsonPath('data.default_warehouse_id', $assigned->id);
        $this->getJson('/api/warehouses?for_pos=1&page[size]=100')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $assigned->id);
        $this->getJson('/api/available-cash-registers?warehouse_id='.$first->id)->assertUnprocessable();
        $assigned->update(['is_active' => false]);
        $this->getJson('/api/config')->assertOk()->assertJsonPath('data.default_warehouse_id', null);
    }

    private function warehouse(Store $store): Warehouse
    {
        $suffix = Str::lower(Str::random(12));
        return Warehouse::create([
            'store_id' => $store->id, 'name' => 'Bodega '.$suffix,
            'email' => $suffix.'@example.test', 'phone' => '0999999999',
            'country' => 'Ecuador', 'city' => 'Manabi', 'is_active' => true,
        ]);
    }

    private function context(): array
    {
        $organization = $this->organization('Principal');
        $store = $this->store($organization, 'Matriz');
        $suffix = Str::lower(Str::random(10));
        $user = User::create([
            'first_name' => 'SaaS',
            'last_name' => 'Tester',
            'email' => "saas-{$suffix}@example.test",
            'phone' => '0999999999',
            'password' => bcrypt('secret123'),
        ]);
        $user->organizations()->attach($organization->id, ['role' => 'OWNER', 'status' => 'ACTIVE']);
        $user->stores()->attach($store->id);

        return [$organization, $store, $user];
    }

    private function organization(string $name): Organization
    {
        $suffix = Str::lower(Str::random(10));
        $plan = SaaSPlan::firstOrCreate(['code' => 'legacy-test'], [
            'name' => 'Plan heredado de pruebas',
            'price' => 0,
            'currency' => 'USD',
            'billing_interval' => 'monthly',
            'billing_interval_count' => 1,
            'trial_days' => 0,
            'grace_days' => 0,
            'features' => ['*'],
            'is_active' => true,
        ]);

        $organization = Organization::create([
            'name' => "{$name} {$suffix}",
            'slug' => "org-{$suffix}",
            'is_active' => true,
        ]);
        OrganizationSubscription::create([
            'organization_id' => $organization->id,
            'saas_plan_id' => $plan->id,
            'status' => OrganizationSubscription::STATUS_ACTIVE,
            'starts_at' => now(),
            'electronic_documents_used' => 0,
        ]);

        return $organization;
    }

    private function store(Organization $organization, string $name): Store
    {
        $suffix = Str::lower(Str::random(10));

        return Store::create([
            'organization_id' => $organization->id,
            'name' => "{$name} {$suffix}",
            'slug' => "store-{$suffix}",
            'is_active' => true,
            'is_default' => true,
        ]);
    }
}
