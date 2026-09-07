<?php

namespace App\Services\SaaS;

use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\SaaSPayment;
use App\Models\SaaSPlan;
use App\Models\SaaSSubscriptionEvent;
use Carbon\CarbonInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BillingService
{
    public function __construct(private readonly PaymentGatewayManager $gateways)
    {
    }

    public function assignPlan(Organization $organization, SaaSPlan $plan, array $options = []): OrganizationSubscription
    {
        return DB::transaction(function () use ($organization, $plan, $options) {
            $subscription = OrganizationSubscription::where('organization_id', $organization->id)
                ->lockForUpdate()->first();
            $now = now();
            $periodEnd = $this->periodEnd($now, $plan);

            if (! $subscription) {
                $subscription = new OrganizationSubscription(['organization_id' => $organization->id]);
            }

            $subscription->fill([
                'saas_plan_id' => $plan->id,
                'status' => $options['status'] ?? OrganizationSubscription::STATUS_ACTIVE,
                'starts_at' => $subscription->starts_at ?: $now,
                'trial_ends_at' => null,
                'current_period_starts_at' => $options['period_starts_at'] ?? $now,
                'current_period_ends_at' => $options['period_ends_at'] ?? $periodEnd,
                'next_billing_at' => $options['period_ends_at'] ?? $periodEnd,
                'auto_renew' => (bool) ($options['auto_renew'] ?? false),
                'cancel_at_period_end' => false,
                'canceled_at' => null,
                'grace_ends_at' => null,
                'electronic_documents_used' => 0,
                'admin_notes' => $options['admin_notes'] ?? $subscription->admin_notes,
            ])->save();

            $this->event($subscription, 'PLAN_ASSIGNED', "Plan {$plan->name} asignado", [
                'plan_id' => $plan->id,
            ]);

            return $subscription->fresh(['organization', 'plan']);
        }, 3);
    }

    public function recordPayment(OrganizationSubscription $subscription, array $data): SaaSPayment
    {
        return DB::transaction(function () use ($subscription, $data) {
            $subscription = OrganizationSubscription::with('plan')->lockForUpdate()->findOrFail($subscription->id);
            $paidAt = isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : now();
            $periodStart = $subscription->current_period_ends_at && $subscription->current_period_ends_at->isFuture()
                ? $subscription->current_period_ends_at->copy()
                : $paidAt->copy();
            $periodEnd = $this->periodEnd($periodStart, $subscription->plan);

            $payment = SaaSPayment::create([
                'organization_id' => $subscription->organization_id,
                'organization_subscription_id' => $subscription->id,
                'saas_plan_id' => $subscription->saas_plan_id,
                'amount' => $data['amount'],
                'currency' => $data['currency'] ?? $subscription->plan->currency,
                'status' => SaaSPayment::STATUS_PAID,
                'method' => $data['method'] ?? 'manual',
                'provider' => $data['provider'] ?? 'manual',
                'provider_reference' => $data['provider_reference'] ?? null,
                'period_starts_at' => $periodStart,
                'period_ends_at' => $periodEnd,
                'paid_at' => $paidAt,
                'metadata' => $data['metadata'] ?? null,
                'recorded_by' => auth()->id(),
            ]);

            $subscription->update([
                'status' => OrganizationSubscription::STATUS_ACTIVE,
                'trial_ends_at' => null,
                'current_period_starts_at' => $periodStart,
                'current_period_ends_at' => $periodEnd,
                'last_payment_at' => $paidAt,
                'next_billing_at' => $periodEnd,
                'grace_ends_at' => null,
                'cancel_at_period_end' => false,
                'canceled_at' => null,
                'electronic_documents_used' => 0,
            ]);

            $this->event($subscription, 'PAYMENT_CONFIRMED', 'Pago confirmado y período renovado', [
                'payment_id' => $payment->id,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'period_ends_at' => $periodEnd->toIso8601String(),
            ]);

            return $payment->fresh(['organization', 'plan', 'recordedBy']);
        }, 3);
    }

    public function approvePendingPayment(SaaSPayment $payment): SaaSPayment
    {
        return DB::transaction(function () use ($payment) {
            $payment = SaaSPayment::with('plan')->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== SaaSPayment::STATUS_PENDING) {
                abort(422, 'Este comprobante ya fue revisado.');
            }

            $subscription = OrganizationSubscription::lockForUpdate()
                ->findOrFail($payment->organization_subscription_id);
            $paidAt = now();
            $isPlanChange = (int) $subscription->saas_plan_id !== (int) $payment->saas_plan_id;
            $periodStart = ! $isPlanChange
                && $subscription->status === OrganizationSubscription::STATUS_ACTIVE
                && $subscription->current_period_ends_at?->isFuture()
                    ? $subscription->current_period_ends_at->copy()
                    : $paidAt->copy();
            $periodEnd = $this->periodEnd($periodStart, $payment->plan);

            $payment->update([
                'status' => SaaSPayment::STATUS_PAID,
                'paid_at' => $paidAt,
                'reviewed_at' => $paidAt,
                'reviewed_by' => auth()->id(),
                'recorded_by' => auth()->id(),
                'period_starts_at' => $periodStart,
                'period_ends_at' => $periodEnd,
                'failure_reason' => null,
            ]);

            $subscription->update([
                'saas_plan_id' => $payment->saas_plan_id,
                'status' => OrganizationSubscription::STATUS_ACTIVE,
                'trial_ends_at' => null,
                'current_period_starts_at' => $periodStart,
                'current_period_ends_at' => $periodEnd,
                'last_payment_at' => $paidAt,
                'next_billing_at' => $periodEnd,
                'grace_ends_at' => null,
                'cancel_at_period_end' => false,
                'canceled_at' => null,
                'electronic_documents_used' => 0,
            ]);
            Organization::whereKey($payment->organization_id)->update(['is_active' => true]);

            $this->event($subscription, 'MANUAL_PAYMENT_APPROVED', $isPlanChange
                ? 'Comprobante aprobado y cambio de plan activado'
                : 'Comprobante aprobado y suscripción renovada', [
                'payment_id' => $payment->id,
                'plan_id' => $payment->saas_plan_id,
                'plan_changed' => $isPlanChange,
                'period_ends_at' => $periodEnd->toIso8601String(),
            ]);

            return $payment->fresh(['organization', 'plan', 'recordedBy', 'reviewedBy']);
        }, 3);
    }

    public function rejectPendingPayment(SaaSPayment $payment, string $reason): SaaSPayment
    {
        return DB::transaction(function () use ($payment, $reason) {
            $payment = SaaSPayment::lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== SaaSPayment::STATUS_PENDING) {
                abort(422, 'Este comprobante ya fue revisado.');
            }
            $payment->update([
                'status' => SaaSPayment::STATUS_FAILED,
                'failed_at' => now(),
                'reviewed_at' => now(),
                'reviewed_by' => auth()->id(),
                'failure_reason' => $reason,
            ]);
            $subscription = OrganizationSubscription::findOrFail($payment->organization_subscription_id);
            $this->event($subscription, 'MANUAL_PAYMENT_REJECTED', 'Comprobante de pago rechazado', [
                'payment_id' => $payment->id,
                'reason' => $reason,
            ]);
            return $payment->fresh(['organization', 'plan', 'reviewedBy']);
        }, 3);
    }

    public function cancel(OrganizationSubscription $subscription, bool $atPeriodEnd, array $context = []): OrganizationSubscription
    {
        return DB::transaction(function () use ($subscription, $atPeriodEnd, $context) {
            $subscription = OrganizationSubscription::lockForUpdate()->findOrFail($subscription->id);
            $subscription->update($atPeriodEnd ? [
                'cancel_at_period_end' => true,
                'auto_renew' => false,
            ] : [
                'status' => OrganizationSubscription::STATUS_CANCELED,
                'cancel_at_period_end' => false,
                'auto_renew' => false,
                'canceled_at' => now(),
            ]);
            $this->event($subscription, $atPeriodEnd ? 'CANCEL_SCHEDULED' : 'CANCELED',
                $atPeriodEnd ? 'Cancelación programada al final del período' : 'Suscripción cancelada',
                $context);

            return $subscription->fresh(['organization', 'plan']);
        }, 3);
    }

    public function reconcileDueSubscriptions(): array
    {
        $result = ['renewed' => 0, 'past_due' => 0, 'canceled' => 0, 'expired' => 0];
        OrganizationSubscription::with('plan')
            ->whereIn('status', [OrganizationSubscription::STATUS_ACTIVE, OrganizationSubscription::STATUS_TRIALING, OrganizationSubscription::STATUS_PAST_DUE])
            ->where(function ($query) {
                $query->where('current_period_ends_at', '<=', now())
                    ->orWhere('trial_ends_at', '<=', now());
            })->chunkById(100, function ($subscriptions) use (&$result) {
                foreach ($subscriptions as $subscription) {
                    if ($subscription->cancel_at_period_end) {
                        $subscription->update(['status' => OrganizationSubscription::STATUS_CANCELED, 'canceled_at' => now()]);
                        $result['canceled']++;
                        continue;
                    }
                    if ($subscription->auto_renew
                        && $subscription->payment_provider
                        && $subscription->provider_subscription_id
                        && ! SaaSPayment::where('organization_subscription_id', $subscription->id)
                            ->where('status', SaaSPayment::STATUS_FAILED)
                            ->whereDate('failed_at', today())->exists()) {
                        try {
                            $charge = $this->gateways->driver($subscription->payment_provider)->charge($subscription);
                            $this->recordPayment($subscription, [
                                'amount' => $charge['amount'],
                                'currency' => $charge['currency'],
                                'method' => 'card',
                                'provider' => $subscription->payment_provider,
                                'provider_reference' => $charge['reference'],
                                'paid_at' => $charge['paid_at'] ?? now(),
                                'metadata' => $charge['metadata'] ?? null,
                            ]);
                            $result['renewed']++;
                            continue;
                        } catch (\Throwable $exception) {
                            $this->recordFailedRenewal($subscription, $exception->getMessage());
                        }
                    }
                    if ($subscription->status === OrganizationSubscription::STATUS_TRIALING) {
                        $subscription->update(['status' => OrganizationSubscription::STATUS_EXPIRED]);
                        $result['expired']++;
                        continue;
                    }
                    $graceEnd = $subscription->grace_ends_at
                        ?: $subscription->current_period_ends_at?->copy()->addDays((int) $subscription->plan->grace_days);
                    if ($graceEnd && $graceEnd->isPast()) {
                        $subscription->update(['status' => OrganizationSubscription::STATUS_EXPIRED, 'grace_ends_at' => $graceEnd]);
                        $result['expired']++;
                    } else {
                        $subscription->update(['status' => OrganizationSubscription::STATUS_PAST_DUE, 'grace_ends_at' => $graceEnd]);
                        $result['past_due']++;
                    }
                }
            });

        return $result;
    }

    private function recordFailedRenewal(OrganizationSubscription $subscription, string $reason): void
    {
        SaaSPayment::create([
            'organization_id' => $subscription->organization_id,
            'organization_subscription_id' => $subscription->id,
            'saas_plan_id' => $subscription->saas_plan_id,
            'amount' => $subscription->plan->price,
            'currency' => $subscription->plan->currency,
            'status' => SaaSPayment::STATUS_FAILED,
            'method' => 'card',
            'provider' => $subscription->payment_provider,
            'failed_at' => now(),
            'failure_reason' => mb_substr($reason, 0, 2000),
        ]);
        $this->event($subscription, 'RENEWAL_FAILED', 'No se pudo completar la renovación automática', [
            'reason' => mb_substr($reason, 0, 500),
        ]);
    }

    private function periodEnd(CarbonInterface $start, SaaSPlan $plan): CarbonInterface
    {
        $count = max(1, (int) $plan->billing_interval_count);
        return match ($plan->billing_interval) {
            'daily' => $start->copy()->addDays($count),
            'weekly' => $start->copy()->addWeeks($count),
            'yearly' => $start->copy()->addYearsNoOverflow($count),
            default => $start->copy()->addMonthsNoOverflow($count),
        };
    }

    private function event(OrganizationSubscription $subscription, string $type, string $description, array $context = []): void
    {
        SaaSSubscriptionEvent::create([
            'organization_subscription_id' => $subscription->id,
            'type' => $type,
            'description' => $description,
            'context' => $context ?: null,
            'performed_by' => auth()->id(),
        ]);
    }
}
