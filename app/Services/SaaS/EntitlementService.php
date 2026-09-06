<?php

namespace App\Services\SaaS;

use App\Exceptions\SubscriptionRestrictionException;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\SaaSUsageReservation;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

class EntitlementService
{
    public const RESOURCE_USERS = 'users';
    public const RESOURCE_STORES = 'stores';
    public const RESOURCE_WAREHOUSES = 'warehouses';
    public const SOURCE_SALE_INVOICE = 'sale_invoice';
    public const SOURCE_CREDIT_NOTE = 'credit_note';

    public function withinResourceLimit(int $organizationId, string $resource, callable $operation): mixed
    {
        return DB::transaction(function () use ($organizationId, $resource, $operation) {
            $subscription = $this->lockedSubscription($organizationId);
            $this->assertWritable($subscription);

            [$limit, $used, $message] = $this->resourceState($subscription, $resource);
            if ($limit !== null && $used >= $limit) {
                throw new SubscriptionRestrictionException($message, "max_{$resource}", 422, [
                    'limit' => $limit,
                    'used' => $used,
                ]);
            }

            return $operation();
        }, 3);
    }

    public function reserveElectronicDocument(
        int $organizationId,
        string $sourceType,
        int $sourceId
    ): bool {
        return DB::transaction(function () use ($organizationId, $sourceType, $sourceId) {
            $subscription = $this->lockedSubscription($organizationId);
            $this->assertWritable($subscription);

            $existing = SaaSUsageReservation::where('metric', SaaSUsageReservation::METRIC_ELECTRONIC_DOCUMENTS)
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->exists();
            if ($existing) {
                return false;
            }

            $limit = $subscription->plan->max_electronic_documents;
            $used = (int) $subscription->electronic_documents_used;
            if ($limit !== null && $used >= $limit) {
                throw new SubscriptionRestrictionException(
                    'Alcanzaste el límite de 10 documentos electrónicos de tu prueba.',
                    'max_electronic_documents',
                    422,
                    ['limit' => $limit, 'used' => $used]
                );
            }

            SaaSUsageReservation::create([
                'organization_subscription_id' => $subscription->id,
                'metric' => SaaSUsageReservation::METRIC_ELECTRONIC_DOCUMENTS,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'reserved_at' => now(),
            ]);
            $subscription->increment('electronic_documents_used');

            return true;
        }, 3);
    }

    public function releaseElectronicDocument(string $sourceType, int $sourceId): void
    {
        DB::transaction(function () use ($sourceType, $sourceId) {
            $reservation = SaaSUsageReservation::where('metric', SaaSUsageReservation::METRIC_ELECTRONIC_DOCUMENTS)
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->lockForUpdate()
                ->first();
            if (! $reservation) {
                return;
            }

            $subscription = OrganizationSubscription::whereKey($reservation->organization_subscription_id)
                ->lockForUpdate()
                ->first();
            $reservation->delete();
            if ($subscription && $subscription->electronic_documents_used > 0) {
                $subscription->decrement('electronic_documents_used');
            }
        }, 3);
    }

    public function assertOrganizationWritable(int $organizationId): void
    {
        $this->assertWritable(
            OrganizationSubscription::with('plan')->where('organization_id', $organizationId)->first()
        );
    }

    public function summary(int $organizationId): array
    {
        $subscription = OrganizationSubscription::with('plan')
            ->where('organization_id', $organizationId)
            ->first();
        if (! $subscription) {
            return ['status' => 'MISSING'];
        }

        $expired = $this->isExpired($subscription);
        $plan = $subscription->plan;
        $organization = Organization::findOrFail($organizationId);
        $users = $organization->users()->count();
        $stores = $organization->stores()->count();
        $warehouses = Warehouse::whereHas('store', fn ($query) => $query->where('organization_id', $organizationId))->count();

        return [
            'plan' => ['code' => $plan->code, 'name' => $plan->name],
            'status' => $expired ? OrganizationSubscription::STATUS_EXPIRED : $subscription->status,
            'is_trial' => $subscription->status === OrganizationSubscription::STATUS_TRIALING,
            'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
            // La pantalla de administración de la suscripción necesita
            // mostrar hasta cuándo está pagado el plan; days_remaining
            // solo cubre la prueba.
            'current_period_ends_at' => $subscription->current_period_ends_at?->toIso8601String(),
            'next_billing_at' => $subscription->next_billing_at?->toIso8601String(),
            'auto_renew' => (bool) $subscription->auto_renew,
            'days_remaining' => $subscription->trial_ends_at && ! $expired
                ? max(1, (int) now()->ceilDay()->diffInDays($subscription->trial_ends_at->ceilDay()))
                : 0,
            'features' => $plan->features ?? [],
            'limits' => [
                'users' => $plan->max_users,
                'stores' => $plan->max_stores,
                'warehouses' => $plan->max_warehouses,
                'electronic_documents' => $plan->max_electronic_documents,
            ],
            'usage' => [
                'users' => $users,
                'stores' => $stores,
                'warehouses' => $warehouses,
                'electronic_documents' => (int) $subscription->electronic_documents_used,
            ],
        ];
    }

    private function lockedSubscription(int $organizationId): OrganizationSubscription
    {
        $subscription = OrganizationSubscription::with('plan')
            ->where('organization_id', $organizationId)
            ->lockForUpdate()
            ->first();
        if (! $subscription) {
            throw new SubscriptionRestrictionException(
                'Esta organización todavía no tiene un plan asignado.',
                'subscription_missing',
                402
            );
        }

        return $subscription;
    }

    private function assertWritable(?OrganizationSubscription $subscription): void
    {
        if (! $subscription) {
            throw new SubscriptionRestrictionException(
                'Esta organización todavía no tiene un plan asignado.',
                'subscription_missing',
                402
            );
        }

        if ($this->isExpired($subscription)) {
            throw new SubscriptionRestrictionException(
                'Tu periodo de prueba de 14 días terminó. Tus datos siguen disponibles en modo consulta.',
                'trial_expired',
                402,
                ['trial_ends_at' => $subscription->trial_ends_at?->toIso8601String()]
            );
        }

        if ($subscription->status === OrganizationSubscription::STATUS_PAST_DUE
            && $subscription->grace_ends_at
            && now()->lessThan($subscription->grace_ends_at)) {
            return;
        }

        if (! in_array($subscription->status, [
            OrganizationSubscription::STATUS_ACTIVE,
            OrganizationSubscription::STATUS_TRIALING,
        ], true)) {
            throw new SubscriptionRestrictionException(
                'La suscripción de esta organización no permite realizar cambios.',
                'subscription_inactive',
                402
            );
        }
    }

    private function isExpired(OrganizationSubscription $subscription): bool
    {
        return in_array($subscription->status, [
                OrganizationSubscription::STATUS_EXPIRED,
                OrganizationSubscription::STATUS_CANCELED,
            ], true)
            || ($subscription->status === OrganizationSubscription::STATUS_TRIALING
                && $subscription->trial_ends_at
                && now()->greaterThanOrEqualTo($subscription->trial_ends_at))
            || ($subscription->status === OrganizationSubscription::STATUS_ACTIVE
                && $subscription->current_period_ends_at
                && now()->greaterThanOrEqualTo(
                    $subscription->current_period_ends_at->copy()->addDays((int) ($subscription->plan?->grace_days ?? 0))
                ))
            || ($subscription->status === OrganizationSubscription::STATUS_PAST_DUE
                && $subscription->grace_ends_at
                && now()->greaterThanOrEqualTo($subscription->grace_ends_at));
    }

    private function resourceState(OrganizationSubscription $subscription, string $resource): array
    {
        $organization = Organization::findOrFail($subscription->organization_id);

        return match ($resource) {
            self::RESOURCE_USERS => [
                $subscription->plan->max_users,
                $organization->users()->count(),
                'Tu plan de prueba permite un solo usuario.',
            ],
            self::RESOURCE_STORES => [
                $subscription->plan->max_stores,
                $organization->stores()->count(),
                'Tu plan de prueba permite una sola tienda.',
            ],
            self::RESOURCE_WAREHOUSES => [
                $subscription->plan->max_warehouses,
                Warehouse::whereHas('store', fn ($query) => $query->where('organization_id', $organization->id))->count(),
                'Tu plan de prueba permite un solo almacén.',
            ],
            default => throw new \InvalidArgumentException("Recurso SaaS desconocido: {$resource}"),
        };
    }
}
