<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\SaaSPayment;
use App\Models\SaaSPlan;
use App\Models\Store;
use App\Services\SaaS\EntitlementService;
use App\Services\SaaS\SubscriptionAdministration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaaSSubscriptionPortalController extends AppBaseController
{
    public function show(Request $request, EntitlementService $entitlements, SubscriptionAdministration $administration): JsonResponse
    {
        $organization = $this->organizationFor($request);
        $canManage = $administration->canManage(
            $request->user('sanctum'),
            $organization
        );
        $subscription = $organization->subscription()->with('plan')->first();
        $summary = $subscription ? $entitlements->summary($organization->id) : ['status' => 'MISSING'];
        $status = $summary['status'] ?? 'MISSING';
        $statusAllowsAccess = in_array($status, [
            OrganizationSubscription::STATUS_ACTIVE,
            OrganizationSubscription::STATUS_TRIALING,
        ], true) || ($status === OrganizationSubscription::STATUS_PAST_DUE
            && $subscription?->grace_ends_at
            && now()->lessThan($subscription->grace_ends_at));
        $canAccess = $organization->is_active && $statusAllowsAccess;

        $reason = ! $organization->is_active
            ? 'ORGANIZATION_INACTIVE'
            : ($canAccess ? 'ACTIVE' : ($status === 'MISSING' ? 'SUBSCRIPTION_MISSING' : 'SUBSCRIPTION_EXPIRED'));

        $pending = $subscription?->payments()->with('plan:id,name,price,currency,billing_interval')
            ->where('status', SaaSPayment::STATUS_PENDING)->latest('id')->first();

        $renewal = $this->renewalAvailability($subscription);

        $offlineAccessUntil = null;
        if ($canAccess) {
            $offlineAccessUntil = now()->addHours((int) config('saas.offline_lease_hours', 12));
            $subscriptionEnd = $status === OrganizationSubscription::STATUS_TRIALING
                ? $subscription?->trial_ends_at
                : ($status === OrganizationSubscription::STATUS_PAST_DUE
                    ? $subscription?->grace_ends_at
                    : $subscription?->current_period_ends_at);
            if ($subscriptionEnd && $subscriptionEnd->lessThan($offlineAccessUntil)) {
                $offlineAccessUntil = $subscriptionEnd;
            }
        }

        return response()->json(['success' => true, 'data' => [
            'can_access' => $canAccess,
            'can_manage' => $canManage,
            'reason' => $reason,
            'organization' => $organization->only(['id', 'name', 'slug', 'is_active']),
            'subscription' => $summary,
            'current_plan_id' => $subscription?->saas_plan_id,
            'offline_access_until' => $offlineAccessUntil?->toIso8601String(),
            'has_pending_payment' => (bool) $pending,
            'pending_payment' => $canManage && $pending ? [
                'id' => $pending->id,
                'status' => $pending->status,
                'amount' => $pending->amount,
                'currency' => $pending->currency,
                'provider_reference' => $pending->provider_reference,
                'submitted_at' => $pending->submitted_at?->toIso8601String(),
                'plan' => $pending->plan?->only(['id', 'name', 'price', 'currency', 'billing_interval']),
            ] : null,
            'renewal' => [
                'can_renew_current_plan' => $renewal['can_renew_current_plan'],
                'window_days' => $renewal['window_days'],
                'available_at' => $renewal['available_at']?->toIso8601String(),
            ],
            'plans' => SaaSPlan::where('is_active', true)->where('code', '!=', 'trial')
                ->orderBy('sort_order')->orderBy('price')->get(),
        ]]);
    }

    public function submitPayment(Request $request, SubscriptionAdministration $administration): JsonResponse
    {
        $organization = $this->organizationFor($request);
        abort_unless($administration->canManage(
            $request->user('sanctum'),
            $organization
        ), 403, 'Solo un administrador de la organización puede gestionar la suscripción.');
        $data = $request->validate([
            'saas_plan_id' => ['required', Rule::exists('saas_plans', 'id')->where(fn ($query) => $query
                ->where('is_active', true)->where('code', '!=', 'trial'))],
            'method' => ['required', Rule::in(['cash', 'transfer', 'deposit'])],
            'submission_key' => 'required|uuid',
            'proof' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
            'billing_name' => 'required|string|max:255',
            'billing_tax_id' => 'required|string|max:30',
            'billing_email' => 'required|email|max:255',
            'billing_phone' => 'nullable|string|max:30',
            'billing_address' => 'nullable|string|max:500',
        ]);
        $plan = SaaSPlan::whereKey($data['saas_plan_id'])->where('is_active', true)
            ->where('code', '!=', 'trial')->firstOrFail();

        $existing = SaaSPayment::where('organization_id', $organization->id)
            ->where('submission_key', $data['submission_key'])->first();
        if ($existing) {
            return response()->json(['success' => true, 'data' => $this->safePayment($existing), 'message' => 'El comprobante ya había sido recibido.']);
        }

        $path = null;
        try {
            $payment = DB::transaction(function () use ($organization, $plan, $data, $request, &$path) {
                $subscription = OrganizationSubscription::where('organization_id', $organization->id)
                    ->lockForUpdate()->firstOrFail();
                $duplicate = SaaSPayment::where('organization_subscription_id', $subscription->id)
                    ->where('submission_key', $data['submission_key'])->first();
                if ($duplicate) {
                    return $duplicate;
                }
                $renewal = $this->renewalAvailability($subscription);
                if ((int) $subscription->saas_plan_id === (int) $plan->id && ! $renewal['can_renew_current_plan']) {
                    $availableAt = optional($renewal['available_at'])->timezone(config('app.timezone'))->format('d/m/Y');
                    $windowDays = $renewal['window_days'];
                    throw ValidationException::withMessages([
                        'saas_plan_id' => "Este plan podrá renovarse desde el {$availableAt}, {$windowDays} días antes de su vencimiento.",
                    ]);
                }
                if ($subscription->payments()->where('status', SaaSPayment::STATUS_PENDING)->exists()) {
                    throw ValidationException::withMessages([
                        'proof' => 'Ya existe un comprobante pendiente de revisión.',
                    ]);
                }

                $path = $request->file('proof')->store("saas-payment-proofs/{$organization->id}", 'saas_private');

                return SaaSPayment::create([
                    'organization_id' => $organization->id,
                    'organization_subscription_id' => $subscription->id,
                    'saas_plan_id' => $plan->id,
                    'amount' => $plan->price,
                    'currency' => $plan->currency,
                    'status' => SaaSPayment::STATUS_PENDING,
                    'method' => $data['method'],
                    'provider' => 'manual_review',
                    'provider_reference' => 'MAN-'.strtoupper(uniqid()),
                    'submission_key' => $data['submission_key'],
                    'proof_path' => $path,
                    'submitted_at' => now(),
                    'metadata' => collect($data)->except(['saas_plan_id', 'method', 'proof', 'submission_key'])->all(),
                ]);
            }, 3);
        } catch (\Throwable $exception) {
            if ($path && Storage::disk('saas_private')->exists($path)) {
                Storage::disk('saas_private')->delete($path);
            }
            throw $exception;
        }

        return response()->json(['success' => true, 'data' => $this->safePayment($payment), 'message' => 'Comprobante enviado para revisión.'], 201);
    }

    public function cancel(
        Request $request,
        SubscriptionAdministration $administration,
        \App\Services\SaaS\BillingService $billing
    ): JsonResponse {
        $organization = $this->organizationFor($request);
        abort_unless($administration->canManage(
            $request->user('sanctum'),
            $organization
        ), 403, 'Solo un administrador de la organización puede gestionar la suscripción.');

        $data = $request->validate([
            'reason' => ['required', Rule::in([
                'TOO_EXPENSIVE', 'MISSING_FEATURES', 'NOT_USING',
                'TECHNICAL_ISSUES', 'BUSINESS_CLOSED', 'OTHER',
            ])],
            'note' => [
                'required_if:reason,OTHER',
                'nullable', 'string', 'max:1000',
            ],
        ], [
            'reason.required' => 'Selecciona el motivo de la cancelación.',
            'reason.in' => 'El motivo seleccionado no es válido.',
            'note.required_if' => 'Escribe una nota cuando selecciones “Otro motivo”.',
            'note.max' => 'La nota no puede superar los 1000 caracteres.',
        ]);

        $subscription = OrganizationSubscription::where('organization_id', $organization->id)->firstOrFail();
        if ($subscription->cancel_at_period_end) {
            throw ValidationException::withMessages([
                'reason' => 'La cancelación de esta suscripción ya está programada.',
            ]);
        }
        if (! in_array($subscription->status, [
            OrganizationSubscription::STATUS_ACTIVE,
            OrganizationSubscription::STATUS_TRIALING,
            OrganizationSubscription::STATUS_PAST_DUE,
        ], true)) {
            throw ValidationException::withMessages([
                'reason' => 'Esta suscripción ya no puede cancelarse.',
            ]);
        }

        $subscription = $billing->cancel($subscription, true, [
            'reason' => $data['reason'],
            'note' => $data['note'] ?? null,
            'requested_by_customer' => true,
        ]);

        return response()->json([
            'success' => true,
            'data' => $subscription,
            'message' => 'La suscripción se cancelará al finalizar el período vigente.',
        ]);
    }

    private function safePayment(SaaSPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'status' => $payment->status,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'provider_reference' => $payment->provider_reference,
            'submitted_at' => $payment->submitted_at?->toIso8601String(),
        ];
    }

    private function renewalAvailability(?OrganizationSubscription $subscription): array
    {
        $windowDays = max(0, (int) config('saas.renewal_window_days', 5));
        $availableAt = null;
        $canRenew = true;

        if ($subscription
            && $subscription->status === OrganizationSubscription::STATUS_ACTIVE
            && $subscription->current_period_ends_at
            && $subscription->current_period_ends_at->isFuture()) {
            $availableAt = $subscription->current_period_ends_at->copy()->subDays($windowDays);
            $canRenew = now()->greaterThanOrEqualTo($availableAt);
        }

        return [
            'can_renew_current_plan' => $canRenew,
            'window_days' => $windowDays,
            'available_at' => $availableAt,
        ];
    }

    private function organizationFor(Request $request): Organization
    {
        $user = $request->user('sanctum');
        $organizations = $user->organizations()
            ->wherePivot('status', Organization::STATUS_ACTIVE)->get();
        $requestedId = $request->header('X-Organization-Id');
        if (! $requestedId && $request->header('X-Store-Id')) {
            $storeId = (int) $request->header('X-Store-Id');
            if ($user->stores()->whereKey($storeId)->exists()) {
                $requestedId = Store::whereKey($storeId)->value('organization_id');
            }
        }
        if (! $requestedId && $organizations->count() > 1) {
            abort(422, 'Debe seleccionar una organización para administrar su suscripción.');
        }

        $organization = $requestedId
            ? $organizations->firstWhere('id', (int) $requestedId)
            : $organizations->first();
        abort_unless($organization, 403, 'No tiene acceso a una organización válida.');
        return $organization;
    }
}
