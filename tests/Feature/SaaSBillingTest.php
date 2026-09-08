<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\SaaSPayment;
use App\Models\SaaSPlan;
use App\Models\User;
use App\Services\SaaS\BillingService;
use App\Services\SaaS\RecurringPaymentGateway;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;
use Laravel\Sanctum\Sanctum;

class SaaSBillingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_confirmed_payment_renews_from_the_existing_period_end(): void
    {
        $organization = Organization::create([
            'name' => 'Organización de prueba de cobro',
            'slug' => 'billing-test-'.uniqid(),
            'is_active' => true,
        ]);
        $plan = SaaSPlan::create([
            'code' => 'billing-'.uniqid(),
            'name' => 'Plan mensual de prueba',
            'price' => 29.90,
            'currency' => 'USD',
            'billing_interval' => 'monthly',
            'billing_interval_count' => 1,
            'trial_days' => 0,
            'grace_days' => 3,
            'features' => ['*'],
            'is_active' => true,
        ]);

        $billing = app(BillingService::class);
        $subscription = $billing->assignPlan($organization, $plan);
        $paidThrough = now()->addDays(10)->startOfSecond();
        $subscription->update([
            'current_period_ends_at' => $paidThrough,
            'next_billing_at' => $paidThrough,
        ]);

        $payment = $billing->recordPayment($subscription->fresh(), [
            'amount' => 29.90,
            'currency' => 'USD',
            'method' => 'transfer',
            'provider_reference' => 'TEST-'.uniqid(),
        ]);

        $this->assertSame(SaaSPayment::STATUS_PAID, $payment->status);
        $this->assertSame(OrganizationSubscription::STATUS_ACTIVE, $subscription->fresh()->status);
        $this->assertTrue($subscription->fresh()->current_period_starts_at->equalTo($paidThrough));
        $this->assertTrue($subscription->fresh()->current_period_ends_at->equalTo($paidThrough->copy()->addMonthNoOverflow()));
        $this->assertDatabaseHas('saas_subscription_events', [
            'organization_subscription_id' => $subscription->id,
            'type' => 'PAYMENT_CONFIRMED',
        ]);
    }

    public function test_regular_user_cannot_access_global_saas_data(): void
    {
        $user = User::create([
            'first_name' => 'Usuario',
            'last_name' => 'Local',
            'email' => 'regular-'.uniqid().'@example.test',
            'phone' => '0999999999',
            'password' => bcrypt('secret123'),
            'language' => 'sp',
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/super-admin/dashboard')->assertForbidden();
    }

    public function test_super_admin_can_access_dashboard_without_store_context(): void
    {
        $user = User::create([
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'email' => 'super-'.uniqid().'@example.test',
            'phone' => '0999999998',
            'password' => bcrypt('secret123'),
            'language' => 'sp',
        ]);
        $user->forceFill([
            'is_super_admin' => true,
            'two_factor_secret' => Crypt::encryptString('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'),
            'two_factor_confirmed_at' => now(),
        ])->save();
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/super-admin/dashboard')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['metrics', 'revenue_series', 'recent_payments', 'expiring']]);
    }

    public function test_due_subscription_is_renewed_by_configured_gateway(): void
    {
        config(['saas.payment_gateways.fake' => FakeRecurringGateway::class]);
        $organization = Organization::create([
            'name' => 'Renovación automática', 'slug' => 'auto-'.uniqid(), 'is_active' => true,
        ]);
        $plan = SaaSPlan::create([
            'code' => 'auto-'.uniqid(), 'name' => 'Automático', 'price' => 19.90,
            'currency' => 'USD', 'billing_interval' => 'monthly', 'billing_interval_count' => 1,
            'trial_days' => 0, 'grace_days' => 3, 'features' => ['*'], 'is_active' => true,
        ]);
        $subscription = OrganizationSubscription::create([
            'organization_id' => $organization->id,
            'saas_plan_id' => $plan->id,
            'status' => OrganizationSubscription::STATUS_ACTIVE,
            'starts_at' => now()->subMonth(),
            'current_period_starts_at' => now()->subMonth(),
            'current_period_ends_at' => now()->subMinute(),
            'next_billing_at' => now()->subMinute(),
            'auto_renew' => true,
            'payment_provider' => 'fake',
            'provider_subscription_id' => 'sub_test',
        ]);

        app(BillingService::class)->reconcileDueSubscriptions();

        $this->assertSame(OrganizationSubscription::STATUS_ACTIVE, $subscription->fresh()->status);
        $this->assertDatabaseHas('saas_payments', [
            'organization_subscription_id' => $subscription->id,
            'status' => SaaSPayment::STATUS_PAID,
            'provider_reference' => 'fake-'.$subscription->id,
        ]);
    }
}

class FakeRecurringGateway implements RecurringPaymentGateway
{
    public function charge(OrganizationSubscription $subscription): array
    {
        return [
            'reference' => 'fake-'.$subscription->id,
            'amount' => (float) $subscription->plan->price,
            'currency' => $subscription->plan->currency,
            'paid_at' => now()->toIso8601String(),
        ];
    }
}
