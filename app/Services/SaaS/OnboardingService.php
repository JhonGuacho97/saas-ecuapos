<?php

namespace App\Services\SaaS;

use App\Models\CashRegister;
use App\Models\CatalogSetting;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SaaSPlan;
use App\Models\OrganizationSubscription;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class OnboardingService
{
    /**
     * Crea una cuenta SaaS completamente operativa. Toda la operación vive
     * dentro de una transacción para impedir tenants parcialmente creados.
     */
    public function create(array $input): array
    {
        $previousTeamId = getPermissionsTeamId();

        try {
            return DB::transaction(function () use ($input) {
                $organization = Organization::create([
                    'name' => trim($input['organization_name']),
                    'slug' => $this->uniqueSlug(Organization::class, $input['organization_name'], 'organizacion'),
                    'is_active' => true,
                ]);

                $trialPlan = SaaSPlan::where('code', 'trial')->where('is_active', true)->firstOrFail();
                OrganizationSubscription::create([
                    'organization_id' => $organization->id,
                    'saas_plan_id' => $trialPlan->id,
                    'status' => OrganizationSubscription::STATUS_TRIALING,
                    'starts_at' => now(),
                    'trial_ends_at' => now()->addDays($trialPlan->trial_days),
                    'electronic_documents_used' => 0,
                ]);

                $storeName = trim($input['store_name'] ?? '') ?: trim($input['organization_name']);
                $store = Store::create([
                    'organization_id' => $organization->id,
                    'name' => $storeName,
                    'slug' => $this->uniqueSlug(Store::class, $storeName, 'tienda'),
                    'is_active' => true,
                    'is_default' => true,
                ]);

                $warehouse = Warehouse::create([
                    'store_id' => $store->id,
                    'is_active' => true,
                    'name' => trim($input['warehouse_name'] ?? '') ?: 'Bodega principal',
                    'phone' => $input['phone'],
                    'country' => 'Ecuador',
                    'city' => $input['city'],
                    'email' => null,
                    'zip_code' => null,
                ]);

                $user = User::create([
                    'first_name' => trim($input['first_name']),
                    'last_name' => trim($input['last_name']),
                    'email' => Str::lower(trim($input['email'])),
                    'phone' => $input['phone'],
                    'password' => Hash::make($input['password']),
                    'language' => 'sp',
                    'default_warehouse_id' => $warehouse->id,
                ]);
                $user->forceFill(['status' => true, 'email_verified_at' => now()])->save();

                $user->organizations()->attach($organization->id, [
                    'role' => Organization::ROLE_OWNER,
                    'status' => Organization::STATUS_ACTIVE,
                ]);
                $user->stores()->attach($store->id);

                setPermissionsTeamId($store->id);
                $adminRole = Role::create([
                    'store_id' => $store->id,
                    'name' => Role::ADMIN,
                    'display_name' => 'Administrador',
                    'guard_name' => 'web',
                ]);
                $adminRole->syncPermissions(Permission::where('guard_name', 'web')->pluck('name')->all());
                $user->assignRole($adminRole);

                $customer = Customer::create([
                    'store_id' => $store->id,
                    'identification' => '9999999999999',
                    'tipo_identificacion' => Customer::TIPO_CONSUMIDOR_FINAL,
                    'es_consumidor_final' => true,
                    'name' => 'Consumidor final',
                    'email' => 'customer@ecua-pos.com',
                    'phone' => '0999999999',
                    'country' => 'Ecuador',
                    'city' => $input['city'],
                    'address' => $input['city'].', '.($input['province'] ?? 'Manabí').', Ecuador',
                ]);

                CashRegister::create([
                    'store_id' => $store->id,
                    'warehouse_id' => $warehouse->id,
                    'name' => 'Caja principal',
                    'code' => 'CAJA-01',
                    'is_active' => true,
                ]);

                CatalogSetting::create([
                    'store_id' => $store->id,
                    'warehouse_id' => $warehouse->id,
                    'is_enabled' => false,
                    'whatsapp_number' => preg_replace('/\D+/', '', $input['phone']),
                    'headline' => 'Compra fácil y rápido',
                    'description' => 'Descubre nuestros productos y realiza tu pedido.',
                    'show_stock' => true,
                    'allow_pickup' => true,
                    'allow_delivery' => false,
                    'delivery_fee' => 0,
                    'minimum_order' => 0,
                ]);

                $this->createStoreSettings($store, $warehouse, $customer, $input);

                return compact('organization', 'store', 'warehouse', 'user');
            }, 3);
        } finally {
            setPermissionsTeamId($previousTeamId);
        }
    }

    private function createStoreSettings(
        Store $store,
        Warehouse $warehouse,
        Customer $customer,
        array $input
    ): void {
        $currency = Currency::firstOrCreate(
            ['code' => 'USD'],
            ['name' => 'US Dollar', 'symbol' => '$']
        );
        $language = Language::firstOrCreate(
            ['iso_code' => 'sp'],
            ['name' => 'Spanish']
        );
        $province = trim($input['province'] ?? '') ?: 'Manabí';
        $address = $input['city'].', '.$province.', Ecuador';

        $settings = [
            'company_name' => trim($input['organization_name']),
            'email' => Str::lower(trim($input['email'])),
            'phone' => $input['phone'],
            'country' => 'Ecuador',
            'state' => $province,
            'city' => $input['city'],
            'postcode' => '',
            'address' => $address,
            'currency' => (string) $currency->id,
            'is_currency_right' => '0',
            'default_language' => (string) $language->id,
            'default_customer' => (string) $customer->id,
            'default_warehouse' => (string) $warehouse->id,
            'date_format' => 'y-m-d',
            'purchase_code' => 'PU',
            'purchase_return_code' => 'PR',
            'sale_code' => 'SA',
            'sale_return_code' => 'SR',
            'expense_code' => 'EX',
            'logo' => 'images/ecua-pos-logo.png',
            'developed' => 'EcuaPosSoft',
            'footer' => 'Desarrollado por EcuaPosSoft. Todos los derechos reservados.',
            'show_app_name_in_sidebar' => '1',
            'show_logo_in_receipt' => '1',
            'show_version_on_footer' => '1',
        ];

        foreach ($settings as $key => $value) {
            Setting::create([
                'store_id' => $store->id,
                'key' => $key,
                'value' => $value,
            ]);
        }
    }

    private function uniqueSlug(string $model, string $name, string $fallback): string
    {
        $base = Str::slug($name) ?: $fallback;
        $slug = $base;
        $suffix = 2;

        while ($model::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
