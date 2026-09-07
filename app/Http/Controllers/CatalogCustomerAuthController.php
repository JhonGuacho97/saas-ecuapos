<?php

namespace App\Http\Controllers;

use App\Services\SaaS\EntitlementService;

use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\CatalogOrder;
use App\Models\Store;
use App\Notifications\CatalogCustomerResetPasswordNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CatalogCustomerAuthController extends Controller
{
    public function session(Request $request, Store $store): JsonResponse
    {
        $this->ensureCatalogAvailable($store);
        $account = Auth::guard('catalog_customer')->user();

        if (!$account || !$account->is_active || (int) $account->store_id !== (int) $store->id) {
            if ($account) {
                Auth::guard('catalog_customer')->logout();
            }

            return response()->json(['data' => [
                'authenticated' => false,
                'customer' => null,
                'csrf_token' => csrf_token(),
            ]]);
        }

        return response()->json(['data' => $this->sessionPayload($account)]);
    }

    public function register(Request $request, Store $store): JsonResponse
    {
        $this->ensureCatalogAvailable($store);
        $data = $request->validate($this->registrationRules($store));
        $email = Str::lower(trim($data['email']));

        $account = DB::transaction(function () use ($data, $email, $store) {
            $customer = Customer::create([
                'store_id' => $store->id,
                'identification' => trim($data['identification']),
                'tipo_identificacion' => $data['tipo_identificacion'],
                'es_consumidor_final' => false,
                'credit_enabled' => false,
                'credit_limit' => 0,
                'default_payment_terms_days' => 0,
                'name' => trim($data['name']),
                'email' => $email,
                'phone' => trim($data['phone']),
                'country' => trim($data['country']),
                'city' => trim($data['city']),
                'address' => trim($data['address']),
                'dob' => $data['dob'] ?? null,
            ]);

            return CustomerAccount::create([
                'store_id' => $store->id,
                'customer_id' => $customer->id,
                'email' => $email,
                'password' => Hash::make($data['password']),
                'is_active' => true,
                'last_login_at' => now(),
            ]);
        });

        Auth::guard('catalog_customer')->login($account);
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Tu cuenta fue creada correctamente.',
            'data' => $this->sessionPayload($account->load('customer')),
        ], 201);
    }

    public function login(Request $request, Store $store): JsonResponse
    {
        $this->ensureCatalogAvailable($store);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $credentials = [
            'store_id' => $store->id,
            'email' => Str::lower(trim($data['email'])),
            'password' => $data['password'],
            'is_active' => true,
        ];

        if (!Auth::guard('catalog_customer')->attempt($credentials, (bool) ($data['remember'] ?? false))) {
            return response()->json([
                'message' => 'El correo o la contraseña no son correctos.',
                'errors' => ['email' => ['El correo o la contraseña no son correctos.']],
            ], 422);
        }

        $request->session()->regenerate();
        $request->session()->regenerateToken();
        /** @var CustomerAccount $account */
        $account = Auth::guard('catalog_customer')->user();
        $account->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'message' => 'Sesión iniciada correctamente.',
            'data' => $this->sessionPayload($account->load('customer')),
        ]);
    }

    public function logout(Request $request, Store $store): JsonResponse
    {
        $account = Auth::guard('catalog_customer')->user();
        if ($account && (int) $account->store_id === (int) $store->id) {
            Auth::guard('catalog_customer')->logout();
            $request->session()->regenerate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'message' => 'Sesión cerrada correctamente.',
            'data' => [
                'authenticated' => false,
                'customer' => null,
                'csrf_token' => csrf_token(),
            ],
        ]);
    }

    public function requestPasswordReset(Request $request, Store $store): JsonResponse
    {
        $this->ensureCatalogAvailable($store);
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $email = Str::lower(trim($data['email']));
        $account = CustomerAccount::query()
            ->where('store_id', $store->id)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('is_active', true)
            ->first();

        if ($account) {
            $token = Str::random(64);
            DB::table('customer_password_reset_tokens')->updateOrInsert(
                ['store_id' => $store->id, 'email' => $email],
                ['token' => Hash::make($token), 'created_at' => now()]
            );

            $resetUrl = route('catalog.show', $store).'?'.http_build_query([
                'reset_token' => $token,
                'email' => $email,
            ]);

            $account->notify(new CatalogCustomerResetPasswordNotification($resetUrl, $store->name));
        }

        return response()->json([
            'message' => 'Si el correo pertenece a una cuenta activa, recibirás un enlace para crear una nueva contraseña.',
        ]);
    }

    public function resetPassword(Request $request, Store $store): JsonResponse
    {
        $this->ensureCatalogAvailable($store);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);
        $email = Str::lower(trim($data['email']));
        $reset = DB::table('customer_password_reset_tokens')
            ->where('store_id', $store->id)
            ->where('email', $email)
            ->first();

        $invalid = !$reset
            || !$reset->created_at
            || now()->subMinutes(60)->greaterThan($reset->created_at)
            || !Hash::check($data['token'], $reset->token);

        if ($invalid) {
            if ($reset && $reset->created_at && now()->subMinutes(60)->greaterThan($reset->created_at)) {
                DB::table('customer_password_reset_tokens')
                    ->where('store_id', $store->id)
                    ->where('email', $email)
                    ->delete();
            }

            return response()->json([
                'message' => 'El enlace no es válido o ya expiró. Solicita uno nuevo.',
                'errors' => ['token' => ['El enlace no es válido o ya expiró.']],
            ], 422);
        }

        $account = CustomerAccount::query()
            ->where('store_id', $store->id)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('is_active', true)
            ->first();

        if (!$account) {
            return response()->json([
                'message' => 'El enlace no es válido o ya expiró. Solicita uno nuevo.',
                'errors' => ['token' => ['El enlace no es válido o ya expiró.']],
            ], 422);
        }

        DB::transaction(function () use ($account, $data, $store, $email) {
            $account->forceFill([
                'password' => Hash::make($data['password']),
                'remember_token' => Str::random(60),
            ])->save();

            DB::table('customer_password_reset_tokens')
                ->where('store_id', $store->id)
                ->where('email', $email)
                ->delete();
        });

        return response()->json([
            'message' => 'Tu contraseña fue actualizada. Ya puedes iniciar sesión.',
        ]);
    }

    public function orders(Store $store): JsonResponse
    {
        $this->ensureCatalogAvailable($store);
        $account = $this->requireAccount($store);
        $orders = CatalogOrder::query()
            ->where('store_id', $store->id)
            ->where('customer_id', $account->customer_id)
            ->withCount('items')
            ->latest()
            ->limit(30)
            ->get()
            ->map(fn (CatalogOrder $order) => $this->orderSummary($order));

        return response()->json(['data' => ['orders' => $orders]]);
    }

    public function order(Store $store, CatalogOrder $catalogOrder): JsonResponse
    {
        $this->ensureCatalogAvailable($store);
        $account = $this->requireAccount($store);
        abort_unless(
            (int) $catalogOrder->store_id === (int) $store->id
            && (int) $catalogOrder->customer_id === (int) $account->customer_id,
            404
        );

        $catalogOrder->load(['items', 'statusHistory']);

        return response()->json(['data' => [
            'order' => array_merge($this->orderSummary($catalogOrder), [
                'delivery_address' => $catalogOrder->delivery_address,
                'payment_method' => $catalogOrder->payment_method,
                'notes' => $catalogOrder->notes,
                'subtotal' => (float) $catalogOrder->subtotal,
                'delivery_fee' => (float) $catalogOrder->delivery_fee,
                'items' => $catalogOrder->items->map(fn ($item) => [
                    'id' => $item->id,
                    'product_name' => $item->product_name,
                    'option' => collect([$item->variant_name, $item->presentation_name])->filter()->implode(' · '),
                    'quantity' => (float) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'line_total' => (float) $item->line_total,
                ]),
                'history' => $catalogOrder->statusHistory->map(fn ($history) => [
                    'status' => $history->to_status,
                    'created_at' => $history->created_at?->toIso8601String(),
                ]),
            ]),
        ]]);
    }

    private function registrationRules(Store $store): array
    {
        return [
            'tipo_identificacion' => ['required', Rule::in([
                Customer::TIPO_RUC,
                Customer::TIPO_CEDULA,
                Customer::TIPO_PASAPORTE,
                Customer::TIPO_EXTERIOR,
            ])],
            'identification' => [
                'required',
                'string',
                'max:30',
                Rule::unique('customers', 'identification')->where(fn ($query) => $query->where('store_id', $store->id)),
            ],
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('customers', 'email')->where(fn ($query) => $query->where('store_id', $store->id)),
                Rule::unique('customer_accounts', 'email')->where(fn ($query) => $query->where('store_id', $store->id)),
            ],
            'phone' => ['required', 'string', 'regex:/^[0-9+()\s-]{7,30}$/'],
            'country' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'address' => ['required', 'string', 'max:1000'],
            'dob' => ['nullable', 'date', 'before_or_equal:today'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'terms' => ['accepted'],
        ];
    }

    private function sessionPayload(CustomerAccount $account): array
    {
        $customer = $account->relationLoaded('customer') ? $account->customer : $account->customer()->first();

        return [
            'authenticated' => true,
            'csrf_token' => csrf_token(),
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'identification' => $customer->identification,
                'tipo_identificacion' => $customer->tipo_identificacion,
                'country' => $customer->country,
                'city' => $customer->city,
                'address' => $customer->address,
                'dob' => $customer->dob,
            ],
        ];
    }

    private function requireAccount(Store $store): CustomerAccount
    {
        /** @var CustomerAccount|null $account */
        $account = Auth::guard('catalog_customer')->user();
        if (!$account || !$account->is_active || (int) $account->store_id !== (int) $store->id) {
            throw new AuthenticationException('Debes iniciar sesión para consultar tus pedidos.', ['catalog_customer']);
        }

        return $account;
    }

    private function orderSummary(CatalogOrder $order): array
    {
        return [
            'id' => $order->id,
            'reference' => $order->reference,
            'status' => $order->status,
            'fulfillment_type' => $order->fulfillment_type,
            'grand_total' => (float) $order->grand_total,
            'items_count' => $order->items_count ?? $order->items->count(),
            'created_at' => $order->created_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
        ];
    }

    private function ensureCatalogAvailable(Store $store): void
    {
        abort_unless($store->is_active, 404);
        abort_unless($store->organization?->is_active, 404);
        app(EntitlementService::class)->assertOrganizationWritable((int) $store->organization_id);
        $setting = $store->catalogSetting;
        abort_unless($setting?->is_enabled && $setting->warehouse_id, 404);
        abort_unless($store->warehouses()->whereKey($setting->warehouse_id)->active()->exists(), 404);
    }
}
