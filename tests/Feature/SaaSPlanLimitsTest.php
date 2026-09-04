<?php

namespace Tests\Feature;

use App\Exceptions\SubscriptionRestrictionException;
use App\Models\OrganizationSubscription;
use App\Services\SaaS\EntitlementService;
use App\Services\SaaS\OnboardingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SaaSPlanLimitsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_new_tenant_receives_the_complete_fourteen_day_trial(): void
    {
        $tenant = $this->trialTenant();
        $summary = app(EntitlementService::class)->summary($tenant['organization']->id);

        $this->assertSame('trial', $summary['plan']['code']);
        $this->assertSame(OrganizationSubscription::STATUS_TRIALING, $summary['status']);
        $this->assertSame(14, $summary['days_remaining']);
        $this->assertSame(['*'], $summary['features']);
        $this->assertSame([
            'users' => 1,
            'stores' => 1,
            'warehouses' => 1,
            'electronic_documents' => 10,
        ], $summary['limits']);
        $this->assertSame([
            'users' => 1,
            'stores' => 1,
            'warehouses' => 1,
            'electronic_documents' => 0,
        ], $summary['usage']);
    }

    public function test_trial_blocks_additional_users_stores_and_warehouses(): void
    {
        $tenant = $this->trialTenant();
        $entitlements = app(EntitlementService::class);

        foreach ([
            EntitlementService::RESOURCE_USERS => 'max_users',
            EntitlementService::RESOURCE_STORES => 'max_stores',
            EntitlementService::RESOURCE_WAREHOUSES => 'max_warehouses',
        ] as $resource => $restriction) {
            try {
                $entitlements->withinResourceLimit(
                    $tenant['organization']->id,
                    $resource,
                    fn () => $this->fail("El límite {$resource} no fue aplicado.")
                );
                $this->fail("Se esperaba la restricción {$restriction}.");
            } catch (SubscriptionRestrictionException $exception) {
                $this->assertSame($restriction, $exception->restriction);
                $this->assertSame(1, $exception->details['limit']);
                $this->assertSame(1, $exception->details['used']);
            }
        }
    }

    public function test_store_endpoint_exposes_the_trial_limit_without_creating_data(): void
    {
        $tenant = $this->trialTenant();
        setPermissionsTeamId($tenant['store']->id);
        Sanctum::actingAs($tenant['user'], ['*']);
        $before = $tenant['organization']->stores()->count();

        $this->withHeaders([
            'X-Organization-Id' => (string) $tenant['organization']->id,
            'X-Store-Id' => (string) $tenant['store']->id,
        ])->postJson('/api/stores', [
            'name' => 'Sucursal adicional '.Str::random(6),
            'is_active' => true,
        ])->assertUnprocessable()
            ->assertJsonPath('restriction', 'max_stores');

        $this->assertSame($before, $tenant['organization']->stores()->count());
    }

    public function test_trial_reserves_only_ten_unique_electronic_documents(): void
    {
        $tenant = $this->trialTenant();
        $entitlements = app(EntitlementService::class);
        $sourceType = 'test_invoice_'.$tenant['organization']->id;

        for ($sourceId = 1; $sourceId <= 10; $sourceId++) {
            $this->assertTrue($entitlements->reserveElectronicDocument(
                $tenant['organization']->id,
                $sourceType,
                $sourceId
            ));
        }

        $this->assertFalse($entitlements->reserveElectronicDocument(
            $tenant['organization']->id,
            $sourceType,
            1
        ));

        $this->assertSame(10, app(EntitlementService::class)
            ->summary($tenant['organization']->id)['usage']['electronic_documents']);

        try {
            $entitlements->reserveElectronicDocument(
                $tenant['organization']->id,
                $sourceType,
                11
            );
            $this->fail('El documento electrónico número once debía ser rechazado.');
        } catch (SubscriptionRestrictionException $exception) {
            $this->assertSame('max_electronic_documents', $exception->restriction);
            $this->assertSame(10, $exception->details['limit']);
            $this->assertSame(10, $exception->details['used']);
        }
    }

    public function test_expired_trial_is_read_only(): void
    {
        $tenant = $this->trialTenant();
        $tenant['organization']->subscription()->update([
            'trial_ends_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($tenant['user'], ['*']);
        $headers = [
            'X-Organization-Id' => (string) $tenant['organization']->id,
            'X-Store-Id' => (string) $tenant['store']->id,
        ];

        $this->withHeaders($headers)->getJson('/api/current-organization')
            ->assertOk()
            ->assertJsonPath('data.subscription.status', OrganizationSubscription::STATUS_EXPIRED);

        $this->withHeaders($headers)->postJson('/api/change-language', ['language' => 'sp'])
            ->assertStatus(402)
            ->assertJsonPath('restriction', 'trial_expired');
    }

    private function trialTenant(): array
    {
        return app(OnboardingService::class)->create([
            'organization_name' => 'Prueba '.Str::random(10),
            'store_name' => 'Matriz',
            'warehouse_name' => 'Bodega principal',
            'first_name' => 'Propietario',
            'last_name' => 'Prueba',
            'email' => 'trial-'.Str::lower(Str::random(12)).'@example.test',
            'phone' => '0991234567',
            'city' => 'Manta',
            'province' => 'Manabí',
            'password' => 'ClaveSaaS123',
        ]);
    }
}
