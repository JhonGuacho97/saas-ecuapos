<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Permission;
use App\Models\SaaSPayment;
use App\Models\SaaSPlan;
use App\Models\SaaSSubscriptionEvent;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SaaSSubscriptionPortalTest extends TestCase
{
    use DatabaseTransactions;

    public function test_expired_member_receives_one_subscription_screen_instead_of_module_errors(): void
    {
        [$user, $organization] = $this->expiredContext();
        Sanctum::actingAs($user, ['*']);

        $this->withHeader('X-Organization-Id', $organization->id)
            ->getJson('/api/subscription-portal')
            ->assertOk()
            ->assertJsonPath('data.can_access', false)
            ->assertJsonPath('data.can_manage', true)
            ->assertJsonPath('data.reason', 'SUBSCRIPTION_EXPIRED');

        $organization->update(['is_active' => false]);
        $this->withHeader('X-Organization-Id', $organization->id)
            ->getJson('/api/subscription-portal')
            ->assertOk()
            ->assertJsonPath('data.reason', 'ORGANIZATION_INACTIVE');
    }

    public function test_member_can_submit_one_manual_payment_proof_for_review(): void
    {
        Storage::fake('saas_private');
        [$user, $organization, $plan, $subscription] = $this->expiredContext();
        Sanctum::actingAs($user, ['*']);
        $submissionKey = (string) Str::uuid();

        $response = $this->withHeader('X-Organization-Id', $organization->id)
            ->post('/api/subscription-portal/payments', [
                'saas_plan_id' => $plan->id,
                'method' => 'transfer',
                'submission_key' => $submissionKey,
                'proof' => UploadedFile::fake()->image('comprobante.jpg'),
                'billing_name' => 'Negocio Demo',
                'billing_tax_id' => '1300000001',
                'billing_email' => 'billing@example.test',
                'billing_phone' => '0999999999',
                'billing_address' => 'Manabí, Ecuador',
            ]);

        $response->assertCreated()->assertJsonPath('data.status', SaaSPayment::STATUS_PENDING)
            ->assertJsonMissingPath('data.proof_path')->assertJsonMissingPath('data.metadata');
        $payment = SaaSPayment::where('organization_subscription_id', $subscription->id)->firstOrFail();
        Storage::disk('saas_private')->assertExists($payment->proof_path);

        // Una repetición de red conserva la misma clave y no crea otro pago.
        $this->withHeader('X-Organization-Id', $organization->id)
            ->post('/api/subscription-portal/payments', [
                'saas_plan_id' => $plan->id,
                'method' => 'transfer',
                'submission_key' => $submissionKey,
                'proof' => UploadedFile::fake()->image('comprobante-repetido.jpg'),
                'billing_name' => 'Negocio Demo',
                'billing_tax_id' => '1300000001',
                'billing_email' => 'billing@example.test',
            ])->assertOk()->assertJsonPath('data.id', $payment->id);
        $this->assertSame(1, SaaSPayment::where('organization_subscription_id', $subscription->id)->count());

        $admin = $this->user(true);
        Sanctum::actingAs($admin, ['*']);
        $this->get("/api/super-admin/payments/{$payment->id}/proof")
            ->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        Sanctum::actingAs($user, ['*']);

        $this->withHeader('X-Organization-Id', $organization->id)
            ->post('/api/subscription-portal/payments', [
                'saas_plan_id' => $plan->id,
                'method' => 'cash',
                'submission_key' => (string) Str::uuid(),
                'proof' => UploadedFile::fake()->image('otro.jpg'),
                'billing_name' => 'Negocio Demo',
                'billing_tax_id' => '1300000001',
                'billing_email' => 'billing@example.test',
            ])->assertUnprocessable();
    }

    public function test_regular_member_cannot_see_pending_payment_billing_data(): void
    {
        [, $organization, $plan, $subscription] = $this->expiredContext();
        $member = $this->user();
        $organization->users()->attach($member->id, [
            'role' => Organization::ROLE_MEMBER, 'status' => Organization::STATUS_ACTIVE,
        ]);
        SaaSPayment::create([
            'organization_id' => $organization->id,
            'organization_subscription_id' => $subscription->id,
            'saas_plan_id' => $plan->id,
            'amount' => $plan->price,
            'currency' => $plan->currency,
            'status' => SaaSPayment::STATUS_PENDING,
            'provider_reference' => 'PRIVATE-'.uniqid(),
            'metadata' => ['billing_tax_id' => '1300000001'],
        ]);
        Sanctum::actingAs($member, ['*']);

        $this->withHeader('X-Organization-Id', $organization->id)
            ->getJson('/api/subscription-portal')
            ->assertOk()
            ->assertJsonPath('data.can_manage', false)
            ->assertJsonPath('data.has_pending_payment', true)
            ->assertJsonPath('data.pending_payment', null)
            ->assertJsonMissing(['billing_tax_id']);
    }

    public function test_a_member_without_full_permissions_cannot_manage_the_subscription(): void
    {
        Storage::fake('saas_private');
        [, $organization, $plan] = $this->expiredContext();
        $member = $this->user();
        $organization->users()->attach($member->id, [
            'role' => Organization::ROLE_MEMBER, 'status' => Organization::STATUS_ACTIVE,
        ]);
        Sanctum::actingAs($member, ['*']);

        $this->withHeader('X-Organization-Id', $organization->id)
            ->getJson('/api/subscription-portal')
            ->assertOk()
            ->assertJsonPath('data.can_access', false)
            ->assertJsonPath('data.can_manage', false);

        $this->withHeader('X-Organization-Id', $organization->id)
            ->post('/api/subscription-portal/payments', [
                'saas_plan_id' => $plan->id,
                'method' => 'transfer',
                'submission_key' => (string) Str::uuid(),
                'proof' => UploadedFile::fake()->image('comprobante.jpg'),
                'billing_name' => 'Negocio Demo',
                'billing_tax_id' => '1300000001',
                'billing_email' => 'billing@example.test',
            ])->assertForbidden();

        $this->assertSame(0, SaaSPayment::where('organization_id', $organization->id)->count());
    }

    public function test_full_permissions_in_another_organization_do_not_grant_billing_access(): void
    {
        [, $targetOrganization] = $this->expiredContext();
        $member = $this->user();
        $targetOrganization->users()->attach($member->id, [
            'role' => Organization::ROLE_MEMBER, 'status' => Organization::STATUS_ACTIVE,
        ]);

        $otherOrganization = Organization::create([
            'name' => 'Otra organización '.uniqid(), 'slug' => 'other-'.uniqid(), 'is_active' => true,
        ]);
        $otherStore = Store::create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Otra tienda', 'slug' => 'other-store-'.uniqid(), 'is_active' => true,
        ]);
        $member->stores()->attach($otherStore->id);
        setPermissionsTeamId($otherStore->id);
        $member->givePermissionTo(Permission::where('guard_name', 'web')->get());
        setPermissionsTeamId(null);

        Sanctum::actingAs($member, ['*']);
        $this->withHeader('X-Organization-Id', $targetOrganization->id)
            ->getJson('/api/subscription-portal')
            ->assertOk()->assertJsonPath('data.can_manage', false);
    }

    public function test_portal_requires_an_explicit_organization_when_user_has_multiple_memberships(): void
    {
        [$user] = $this->expiredContext();
        $otherOrganization = Organization::create([
            'name' => 'Segunda organización '.uniqid(),
            'slug' => 'second-'.uniqid(),
            'is_active' => true,
        ]);
        $otherOrganization->users()->attach($user->id, [
            'role' => Organization::ROLE_OWNER,
            'status' => Organization::STATUS_ACTIVE,
        ]);
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/subscription-portal')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Debe seleccionar una organización para administrar su suscripción.');
    }

    public function test_super_admin_approval_reactivates_organization_and_subscription(): void
    {
        [$member, $organization, $plan, $subscription] = $this->expiredContext();
        $organization->update(['is_active' => false]);
        $payment = SaaSPayment::create([
            'organization_id' => $organization->id,
            'organization_subscription_id' => $subscription->id,
            'saas_plan_id' => $plan->id,
            'amount' => $plan->price,
            'currency' => $plan->currency,
            'status' => SaaSPayment::STATUS_PENDING,
            'method' => 'cash',
            'provider_reference' => 'TEST-MANUAL-'.uniqid(),
            'submitted_at' => now(),
        ]);
        $admin = $this->user(true);
        Sanctum::actingAs($admin, ['*']);

        $this->postJson("/api/super-admin/payments/{$payment->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', SaaSPayment::STATUS_PAID);

        $this->assertTrue($organization->fresh()->is_active);
        $this->assertSame(OrganizationSubscription::STATUS_ACTIVE, $subscription->fresh()->status);
        $this->assertSame($plan->id, $subscription->fresh()->saas_plan_id);
    }

    public function test_current_plan_can_only_be_renewed_during_its_last_five_days(): void
    {
        Storage::fake('saas_private');
        [$user, $organization, $plan, $subscription] = $this->activeContext(now()->addDays(20));
        Sanctum::actingAs($user, ['*']);

        $this->withHeader('X-Organization-Id', $organization->id)
            ->getJson('/api/subscription-portal')
            ->assertOk()
            ->assertJsonPath('data.renewal.can_renew_current_plan', false)
            ->assertJsonPath('data.renewal.window_days', 5);

        $this->submitProof($organization, $plan)
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
        $this->assertSame(0, SaaSPayment::where('organization_subscription_id', $subscription->id)->count());

        $subscription->update([
            'current_period_ends_at' => now()->addDays(4),
            'next_billing_at' => now()->addDays(4),
        ]);

        $this->withHeader('X-Organization-Id', $organization->id)
            ->getJson('/api/subscription-portal')
            ->assertOk()
            ->assertJsonPath('data.renewal.can_renew_current_plan', true);
        $this->submitProof($organization, $plan)->assertCreated();
    }

    public function test_owner_can_schedule_cancellation_with_reason_without_losing_paid_access(): void
    {
        [$owner, $organization, , $subscription] = $this->activeContext(now()->addDays(20));
        Sanctum::actingAs($owner, ['*']);

        $this->withHeader('X-Organization-Id', $organization->id)
            ->postJson('/api/subscription-portal/cancel', [
                'reason' => 'MISSING_FEATURES',
                'note' => 'Necesitamos una integración adicional.',
            ])
            ->assertOk()
            ->assertJsonPath('data.cancel_at_period_end', true);

        $fresh = $subscription->fresh();
        $this->assertSame(OrganizationSubscription::STATUS_ACTIVE, $fresh->status);
        $this->assertTrue($fresh->cancel_at_period_end);
        $this->assertFalse($fresh->auto_renew);
        $this->assertDatabaseHas('saas_subscription_events', [
            'organization_subscription_id' => $subscription->id,
            'type' => 'CANCEL_SCHEDULED',
            'performed_by' => $owner->id,
        ]);
        $event = SaaSSubscriptionEvent::where('organization_subscription_id', $subscription->id)
            ->where('type', 'CANCEL_SCHEDULED')->latest('id')->firstOrFail();
        $this->assertSame('MISSING_FEATURES', $event->context['reason']);
        $this->assertSame('Necesitamos una integración adicional.', $event->context['note']);

        $this->withHeader('X-Organization-Id', $organization->id)
            ->getJson('/api/subscription-portal')
            ->assertOk()
            ->assertJsonPath('data.can_access', true)
            ->assertJsonPath('data.subscription.cancel_at_period_end', true);
    }

    public function test_other_cancellation_reason_requires_a_note(): void
    {
        [$owner, $organization, , $subscription] = $this->activeContext(now()->addDays(20));
        Sanctum::actingAs($owner, ['*']);

        $this->withHeader('X-Organization-Id', $organization->id)
            ->postJson('/api/subscription-portal/cancel', ['reason' => 'OTHER'])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Escribe una nota cuando selecciones “Otro motivo”.');

        $this->assertFalse($subscription->fresh()->cancel_at_period_end);
    }

    public function test_approved_plan_change_starts_immediately_instead_of_using_the_old_expiration(): void
    {
        [$owner, $organization, , $subscription] = $this->activeContext(now()->addDays(20));
        $newPlan = SaaSPlan::create([
            'code' => 'upgrade-'.uniqid(), 'name' => 'Plan superior', 'price' => 49.90,
            'currency' => 'USD', 'billing_interval' => 'monthly', 'billing_interval_count' => 1,
            'trial_days' => 0, 'grace_days' => 3, 'features' => ['*'], 'is_active' => true,
        ]);
        $payment = SaaSPayment::create([
            'organization_id' => $organization->id,
            'organization_subscription_id' => $subscription->id,
            'saas_plan_id' => $newPlan->id,
            'amount' => $newPlan->price,
            'currency' => $newPlan->currency,
            'status' => SaaSPayment::STATUS_PENDING,
            'method' => 'transfer',
            'provider_reference' => 'UPGRADE-'.uniqid(),
            'submitted_at' => now(),
        ]);
        $approvedAt = now();
        Sanctum::actingAs($this->user(true), ['*']);

        $this->postJson("/api/super-admin/payments/{$payment->id}/approve")->assertOk();

        $fresh = $subscription->fresh();
        $this->assertSame($newPlan->id, $fresh->saas_plan_id);
        $this->assertTrue($fresh->current_period_starts_at->betweenIncluded(
            $approvedAt->copy()->subSecond(),
            now()->addSecond()
        ));
        $this->assertTrue($fresh->current_period_ends_at->equalTo(
            $fresh->current_period_starts_at->copy()->addMonthNoOverflow()
        ));
    }

    private function submitProof(Organization $organization, SaaSPlan $plan)
    {
        return $this->withHeader('X-Organization-Id', $organization->id)
            ->post('/api/subscription-portal/payments', [
                'saas_plan_id' => $plan->id,
                'method' => 'transfer',
                'submission_key' => (string) Str::uuid(),
                'proof' => UploadedFile::fake()->image('comprobante.jpg'),
                'billing_name' => 'Negocio Demo',
                'billing_tax_id' => '1300000001',
                'billing_email' => 'billing@example.test',
            ]);
    }

    private function activeContext($periodEnd): array
    {
        [$user, $organization, $plan, $subscription] = $this->expiredContext();
        $subscription->update([
            'status' => OrganizationSubscription::STATUS_ACTIVE,
            'trial_ends_at' => null,
            'current_period_starts_at' => now()->subDays(10),
            'current_period_ends_at' => $periodEnd,
            'next_billing_at' => $periodEnd,
        ]);

        return [$user, $organization, $plan, $subscription->fresh()];
    }

    private function expiredContext(): array
    {
        $organization = Organization::create([
            'name' => 'Organización vencida '.uniqid(), 'slug' => 'expired-'.uniqid(), 'is_active' => true,
        ]);
        $user = $this->user();
        $organization->users()->attach($user->id, ['role' => Organization::ROLE_OWNER, 'status' => Organization::STATUS_ACTIVE]);
        $plan = SaaSPlan::create([
            'code' => 'portal-'.uniqid(), 'name' => 'Plan Comercial', 'price' => 24.90,
            'currency' => 'USD', 'billing_interval' => 'monthly', 'billing_interval_count' => 1,
            'trial_days' => 0, 'grace_days' => 0, 'features' => ['*'], 'is_active' => true,
        ]);
        $subscription = OrganizationSubscription::create([
            'organization_id' => $organization->id, 'saas_plan_id' => $plan->id,
            'status' => OrganizationSubscription::STATUS_EXPIRED, 'starts_at' => now()->subDays(15),
            'trial_ends_at' => now()->subDay(), 'auto_renew' => false,
        ]);
        return [$user, $organization, $plan, $subscription];
    }

    private function user(bool $superAdmin = false): User
    {
        $user = User::create([
            'first_name' => $superAdmin ? 'Super' : 'Cliente', 'last_name' => 'SaaS',
            'email' => uniqid('portal-').'@example.test', 'phone' => '0999999999',
            'password' => bcrypt('secret123'), 'language' => 'sp', 'status' => true,
        ]);

        if ($superAdmin) {
            $user->forceFill([
                'is_super_admin' => true,
                'two_factor_secret' => Crypt::encryptString('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'),
                'two_factor_confirmed_at' => now(),
            ])->save();
        }

        return $user;
    }
}
