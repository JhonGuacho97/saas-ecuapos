<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\SaaSPayment;
use App\Models\SaaSPlan;
use App\Models\User;
use App\Services\SaaS\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SaaSSuperAdminController extends AppBaseController
{
    public function dashboard(): JsonResponse
    {
        $from = now()->subDays(29)->startOfDay();
        $paid = SaaSPayment::where('status', SaaSPayment::STATUS_PAID)->where('paid_at', '>=', $from)->get();
        $series = collect(range(0, 29))->map(function ($offset) use ($from, $paid) {
            $date = $from->copy()->addDays($offset);
            return [
                'date' => $date->toDateString(),
                'amount' => round((float) $paid->filter(fn ($payment) => $payment->paid_at?->isSameDay($date))->sum('amount'), 2),
            ];
        });

        return response()->json(['success' => true, 'data' => [
            'metrics' => [
                'organizations' => Organization::count(),
                'active_organizations' => Organization::where('is_active', true)->count(),
                'active_subscriptions' => OrganizationSubscription::where('status', OrganizationSubscription::STATUS_ACTIVE)->count(),
                'trials' => OrganizationSubscription::where('status', OrganizationSubscription::STATUS_TRIALING)->count(),
                'past_due' => OrganizationSubscription::whereIn('status', [OrganizationSubscription::STATUS_PAST_DUE, OrganizationSubscription::STATUS_EXPIRED])->count(),
                'monthly_revenue' => (float) SaaSPayment::where('status', SaaSPayment::STATUS_PAID)->where('paid_at', '>=', now()->startOfMonth())->sum('amount'),
            ],
            'revenue_series' => $series,
            'recent_payments' => SaaSPayment::with(['organization:id,name', 'plan:id,name'])
                ->latest('id')->limit(6)->get(),
            'expiring' => OrganizationSubscription::with(['organization:id,name', 'plan:id,name'])
                ->whereIn('status', [OrganizationSubscription::STATUS_ACTIVE, OrganizationSubscription::STATUS_TRIALING])
                ->where(function ($query) {
                    $query->whereBetween('trial_ends_at', [now(), now()->addDays(7)])
                        ->orWhereBetween('current_period_ends_at', [now(), now()->addDays(7)]);
                })->limit(8)->get(),
        ]]);
    }

    public function organizations(Request $request): JsonResponse
    {
        $search = trim((string) $request->get('search'));
        $organizations = Organization::query()
            ->with(['subscription.plan'])
            ->withCount(['users', 'stores'])
            ->when($search, fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->latest('id')->paginate(min(50, max(5, (int) $request->get('per_page', 15))));

        return response()->json(['success' => true, 'data' => $organizations]);
    }

    public function updateOrganization(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate([
            'is_active' => 'required|boolean',
            'suspension_reason' => ['nullable', Rule::in([
                Organization::SUSPENSION_BILLING,
                Organization::SUSPENSION_ADMINISTRATIVE,
                Organization::SUSPENSION_SECURITY,
            ])],
            'suspension_note' => 'nullable|string|max:1000',
        ]);
        $organization->update($data['is_active'] ? [
            'is_active' => true,
            'suspension_reason' => null,
            'suspended_at' => null,
            'suspension_note' => null,
        ] : [
            'is_active' => false,
            'suspension_reason' => $data['suspension_reason'] ?? Organization::SUSPENSION_ADMINISTRATIVE,
            'suspended_at' => now(),
            'suspension_note' => $data['suspension_note'] ?? null,
        ]);
        return response()->json(['success' => true, 'data' => $organization, 'message' => 'Organización actualizada.']);
    }

    public function users(Request $request): JsonResponse
    {
        $search = trim((string) $request->get('search'));
        $users = User::query()->with(['organizations:id,name'])->when($search, function ($query) use ($search) {
            $query->where(function ($nested) use ($search) {
                $nested->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        })->latest('id')->paginate(min(50, max(5, (int) $request->get('per_page', 15))));
        return response()->json(['success' => true, 'data' => $users]);
    }

    public function plans(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => SaaSPlan::withCount('subscriptions')->orderBy('sort_order')->orderBy('id')->get()]);
    }

    public function storePlan(Request $request): JsonResponse
    {
        $plan = SaaSPlan::create($this->validatePlan($request));
        return response()->json(['success' => true, 'data' => $plan, 'message' => 'Plan creado.'], 201);
    }

    public function updatePlan(Request $request, SaaSPlan $plan): JsonResponse
    {
        $plan->update($this->validatePlan($request, $plan));
        return response()->json(['success' => true, 'data' => $plan->fresh()->loadCount('subscriptions'), 'message' => 'Plan actualizado.']);
    }

    public function subscriptions(Request $request): JsonResponse
    {
        $search = trim((string) $request->get('search'));
        $status = $request->get('status');
        $subscriptions = OrganizationSubscription::with(['organization:id,name,is_active', 'plan:id,name,price,currency,billing_interval'])
            ->when($search, fn ($query) => $query->whereHas('organization', fn ($org) => $org->where('name', 'like', "%{$search}%")))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->latest('id')->paginate(min(50, max(5, (int) $request->get('per_page', 15))));
        return response()->json(['success' => true, 'data' => $subscriptions]);
    }

    public function assignPlan(Request $request, Organization $organization, BillingService $billing): JsonResponse
    {
        $data = $request->validate([
            'saas_plan_id' => ['required', Rule::exists('saas_plans', 'id')->where('is_active', true)],
            'auto_renew' => 'sometimes|boolean',
            'admin_notes' => 'nullable|string|max:2000',
        ]);
        $subscription = $billing->assignPlan($organization, SaaSPlan::findOrFail($data['saas_plan_id']), $data);
        return response()->json(['success' => true, 'data' => $subscription, 'message' => 'Plan asignado correctamente.']);
    }

    public function updateSubscription(Request $request, OrganizationSubscription $subscription): JsonResponse
    {
        $data = $request->validate([
            'auto_renew' => 'sometimes|boolean',
            'admin_notes' => 'nullable|string|max:2000',
            'payment_provider' => 'nullable|string|max:40',
            'provider_customer_id' => 'nullable|string|max:255',
            'provider_subscription_id' => 'nullable|string|max:255',
        ]);
        $subscription->update($data);
        return response()->json(['success' => true, 'data' => $subscription->fresh(['organization', 'plan']), 'message' => 'Suscripción actualizada.']);
    }

    public function cancelSubscription(Request $request, OrganizationSubscription $subscription, BillingService $billing): JsonResponse
    {
        $data = $request->validate(['at_period_end' => 'required|boolean']);
        return response()->json(['success' => true, 'data' => $billing->cancel($subscription, $data['at_period_end']), 'message' => 'Cancelación registrada.']);
    }

    public function payments(Request $request): JsonResponse
    {
        $payments = SaaSPayment::with(['organization:id,name', 'plan:id,name', 'recordedBy:id,first_name,last_name'])
            ->when($request->get('status'), fn ($query, $status) => $query->where('status', $status))
            ->latest('id')->paginate(min(50, max(5, (int) $request->get('per_page', 15))));
        return response()->json(['success' => true, 'data' => $payments]);
    }

    public function paymentProof(SaaSPayment $payment)
    {
        abort_unless($payment->proof_path, 404, 'Este pago no tiene comprobante adjunto.');

        $disk = Storage::disk('saas_private');
        abort_unless($disk->exists($payment->proof_path), 404, 'El comprobante no está disponible.');

        return $disk->response($payment->proof_path, basename($payment->proof_path), [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Disposition' => 'inline; filename="'.basename($payment->proof_path).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function approvePayment(SaaSPayment $payment, BillingService $billing): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $billing->approvePendingPayment($payment), 'message' => 'Comprobante aprobado y suscripción renovada.']);
    }

    public function rejectPayment(Request $request, SaaSPayment $payment, BillingService $billing): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:1000']);
        return response()->json(['success' => true, 'data' => $billing->rejectPendingPayment($payment, $data['reason']), 'message' => 'Comprobante rechazado.']);
    }

    public function recordPayment(Request $request, OrganizationSubscription $subscription, BillingService $billing): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'method' => ['required', Rule::in(['cash', 'transfer', 'card', 'deposit', 'manual'])],
            'provider' => 'nullable|string|max:40',
            'provider_reference' => 'nullable|string|max:255|unique:saas_payments,provider_reference',
            'paid_at' => 'nullable|date',
        ]);
        $payment = $billing->recordPayment($subscription, $data);
        return response()->json(['success' => true, 'data' => $payment, 'message' => 'Pago confirmado y suscripción renovada.'], 201);
    }

    private function validatePlan(Request $request, ?SaaSPlan $plan = null): array
    {
        return $request->validate([
            'code' => ['required', 'alpha_dash', 'max:50', Rule::unique('saas_plans', 'code')->ignore($plan?->id)],
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'price' => 'required|numeric|min:0',
            'currency' => 'required|string|size:3',
            'billing_interval' => ['required', Rule::in(['daily', 'weekly', 'monthly', 'yearly'])],
            'billing_interval_count' => 'required|integer|min:1|max:24',
            'trial_days' => 'required|integer|min:0|max:365',
            'grace_days' => 'required|integer|min:0|max:60',
            'max_users' => 'nullable|integer|min:1',
            'max_stores' => 'nullable|integer|min:1',
            'max_warehouses' => 'nullable|integer|min:1',
            'max_electronic_documents' => 'nullable|integer|min:1',
            'features' => 'nullable|array',
            'features.*' => 'string|max:100',
            'sort_order' => 'nullable|integer|min:0|max:999',
            'is_active' => 'required|boolean',
        ]);
    }
}
