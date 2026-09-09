<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\SaaSPlan;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class OfflineSyncTokenTest extends TestCase
{
    use DatabaseTransactions;

    /** @test */
    public function it_issues_a_store_scoped_device_token_with_only_sync_abilities(): void
    {
        $store = Store::create([
            'name' => 'Offline Test Store',
            'slug' => 'offline-test-' . Str::lower(Str::random(8)),
            'is_active' => true,
        ]);
        $user = User::create([
            'first_name' => 'Offline',
            'last_name' => 'Tester',
            'email' => Str::uuid() . '@example.test',
            'phone' => '0999999999',
            'password' => bcrypt('secret123'),
            'status' => true,
        ]);
        $user->stores()->attach($store->id);
        $subscription = $this->attachActiveSubscription($store, $user, now()->addHours(2));

        setPermissionsTeamId($store->id);
        $permission = Permission::firstOrCreate([
            'name' => 'manage_pos_screen',
            'guard_name' => 'web',
        ]);
        $user->givePermissionTo($permission);
        Sanctum::actingAs($user, ['*']);

        $deviceId = (string) Str::uuid();
        $response = $this->withHeader('X-Store-Id', $store->id)
            ->postJson('/api/offline-sync/device-token', [
                'device_id' => $deviceId,
                'device_name' => 'PHPUnit',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.store_id', $store->id)
            ->assertJsonPath('data.device_id', $deviceId)
            ->assertJsonStructure(['data' => ['token', 'expires_at']]);

        $token = $user->tokens()->where('name', "offline-sync:{$store->id}:{$deviceId}")->firstOrFail();
        $this->assertSame([
            'offline-sales:sync',
            'offline-customers:sync',
            "store:{$store->id}",
        ], $token->abilities);
        $this->assertNotNull($token->expires_at);
        $this->assertTrue($token->expires_at->lessThanOrEqualTo($subscription->current_period_ends_at));
        $this->assertFalse($token->can('*'));
    }

    /** @test */
    public function regular_login_tokens_keep_the_normal_session_expiration(): void
    {
        $user = User::create([
            'first_name' => 'Session',
            'last_name' => 'Tester',
            'email' => Str::uuid() . '@example.test',
            'phone' => '0999999998',
            'password' => Hash::make('secret123'),
            'status' => true,
        ]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secret123',
            'language_code' => 'es',
        ])->assertOk()->assertJsonStructure(['data' => ['token']]);

        $token = $user->tokens()->where('name', 'token')->latest('id')->firstOrFail();
        $expectedMinutes = (int) config('sanctum.session_token_expiration');
        $this->assertNotNull($token->expires_at);
        $this->assertTrue($token->expires_at->between(
            now()->addMinutes($expectedMinutes)->subMinute(),
            now()->addMinutes($expectedMinutes)->addMinute()
        ));
    }

    /** @test */
    public function offline_customer_sync_maps_repeated_identification_without_duplicates(): void
    {
        $store = Store::create([
            'name' => 'Customer Sync Store',
            'slug' => 'customer-sync-' . Str::lower(Str::random(8)),
            'is_active' => true,
        ]);
        $user = User::create([
            'first_name' => 'Customer',
            'last_name' => 'Sync',
            'email' => Str::uuid() . '@example.test',
            'phone' => '0999999997',
            'password' => Hash::make('secret123'),
            'status' => true,
        ]);
        $user->stores()->attach($store->id);
        $this->attachActiveSubscription($store, $user, now()->addDay());
        setPermissionsTeamId($store->id);
        $user->givePermissionTo(Permission::firstOrCreate([
            'name' => 'manage_pos_screen',
            'guard_name' => 'web',
        ]));
        $token = $user->createToken('offline-sync:test', [
            'offline-customers:sync',
            "store:{$store->id}",
        ], now()->addDay())->plainTextToken;

        $payload = [
            'client_uuid' => (string) Str::uuid(),
            'identification' => '1300000001',
            'tipo_identificacion' => '05',
            'name' => 'Cliente Offline',
            'email' => 'offline-one-' . Str::random(6) . '@example.test',
            'phone' => '0999999999',
            'country' => 'Ecuador',
            'city' => 'Manta',
            'address' => 'Dirección de prueba',
        ];

        $frontendHeaders = [
            'X-Store-Id' => $store->id,
            'Origin' => rtrim(config('app.url'), '/'),
            'Referer' => rtrim(config('app.url'), '/') . '/',
        ];
        $first = $this->withToken($token)->withHeaders($frontendHeaders)
            ->postJson('/api/offline-sync/customers', $payload)
            ->assertOk();
        $secondUuid = (string) Str::uuid();
        $second = $this->withToken($token)->withHeaders($frontendHeaders)
            ->postJson('/api/offline-sync/customers', [
                ...$payload,
                'client_uuid' => $secondUuid,
                'email' => 'offline-two-' . Str::random(6) . '@example.test',
            ])->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Customer::where('store_id', $store->id)
            ->where('identification', '1300000001')->count());
        $this->assertSame(2, DB::table('offline_customer_identities')
            ->where('store_id', $store->id)->count());
    }

    /** @test */
    public function an_existing_offline_token_cannot_write_after_the_subscription_expires(): void
    {
        $store = Store::create([
            'name' => 'Expired Offline Store',
            'slug' => 'expired-offline-' . Str::lower(Str::random(8)),
            'is_active' => true,
        ]);
        $user = User::create([
            'first_name' => 'Expired',
            'last_name' => 'Offline',
            'email' => Str::uuid() . '@example.test',
            'phone' => '0999999996',
            'password' => Hash::make('secret123'),
            'status' => true,
        ]);
        $user->stores()->attach($store->id);
        $subscription = $this->attachActiveSubscription($store, $user, now()->addDay());
        setPermissionsTeamId($store->id);
        $user->givePermissionTo(Permission::firstOrCreate([
            'name' => 'manage_pos_screen',
            'guard_name' => 'web',
        ]));

        $token = $user->createToken('offline-sync:expired', [
            'offline-customers:sync',
            "store:{$store->id}",
        ], now()->addDay())->plainTextToken;

        $subscription->update(['current_period_ends_at' => now()->subMinute()]);

        $this->withToken($token)->withHeaders([
            'X-Store-Id' => $store->id,
            'X-Organization-Id' => $subscription->organization_id,
        ])->postJson('/api/offline-sync/customers', [
            'client_uuid' => (string) Str::uuid(),
            'identification' => '1300000002',
            'tipo_identificacion' => '05',
            'name' => 'No debe guardarse',
        ])->assertStatus(402);

        $this->assertDatabaseMissing('customers', [
            'store_id' => $store->id,
            'identification' => '1300000002',
        ]);
    }

    /** @test */
    public function a_suspended_tenant_can_revoke_its_offline_device_token(): void
    {
        $store = Store::create([
            'name' => 'Revoke Offline Store',
            'slug' => 'revoke-offline-' . Str::lower(Str::random(8)),
            'is_active' => true,
        ]);
        $user = User::create([
            'first_name' => 'Revoke',
            'last_name' => 'Offline',
            'email' => Str::uuid() . '@example.test',
            'phone' => '0999999995',
            'password' => Hash::make('secret123'),
            'status' => true,
        ]);
        $user->stores()->attach($store->id);
        $subscription = $this->attachActiveSubscription($store, $user, now()->addDay());
        $deviceId = (string) Str::uuid();
        $user->createToken("offline-sync:{$store->id}:{$deviceId}", [
            'offline-sales:sync', "store:{$store->id}",
        ], now()->addDay());
        $sessionToken = $user->createToken('browser-session', ['*'])->plainTextToken;
        Organization::whereKey($subscription->organization_id)->update([
            'is_active' => false,
            'suspension_reason' => Organization::SUSPENSION_ADMINISTRATIVE,
        ]);

        $this->withToken($sessionToken)->withHeader('X-Store-Id', $store->id)
            ->deleteJson('/api/offline-sync/device-token', ['device_id' => $deviceId])
            ->assertOk();

        $this->assertSame(0, $user->tokens()
            ->where('name', "offline-sync:{$store->id}:{$deviceId}")->count());
    }

    private function attachActiveSubscription(Store $store, User $user, $periodEndsAt): OrganizationSubscription
    {
        $organization = Organization::create([
            'name' => 'Offline Organization ' . Str::random(6),
            'slug' => 'offline-org-' . Str::lower(Str::random(8)),
            'is_active' => true,
        ]);
        $organization->users()->attach($user->id, [
            'role' => Organization::ROLE_OWNER,
            'status' => Organization::STATUS_ACTIVE,
        ]);
        $store->update(['organization_id' => $organization->id]);
        $plan = SaaSPlan::create([
            'code' => 'offline-' . Str::lower(Str::random(8)),
            'name' => 'Offline Test Plan',
            'price' => 10,
            'currency' => 'USD',
            'billing_interval' => 'monthly',
            'billing_interval_count' => 1,
            'trial_days' => 0,
            'grace_days' => 0,
            'features' => ['*'],
            'is_active' => true,
        ]);

        return OrganizationSubscription::create([
            'organization_id' => $organization->id,
            'saas_plan_id' => $plan->id,
            'status' => OrganizationSubscription::STATUS_ACTIVE,
            'starts_at' => now(),
            'current_period_starts_at' => now(),
            'current_period_ends_at' => $periodEndsAt,
            'auto_renew' => false,
        ]);
    }
}
