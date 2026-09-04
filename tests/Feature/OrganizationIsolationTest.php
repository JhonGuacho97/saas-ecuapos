<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
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

        return Organization::create([
            'name' => "{$name} {$suffix}",
            'slug' => "org-{$suffix}",
            'is_active' => true,
        ]);
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
