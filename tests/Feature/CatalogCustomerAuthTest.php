<?php

namespace Tests\Feature;

use App\Models\CatalogSetting;
use App\Models\CatalogOrder;
use App\Models\BaseUnit;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\ManageStock;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductPresentation;
use App\Models\PresentationFamily;
use App\Models\PresentationType;
use App\Models\Store;
use App\Models\SaaSPlan;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use App\Notifications\CatalogCustomerResetPasswordNotification;
use Tests\TestCase;

class CatalogCustomerAuthTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_customer_can_register_and_receive_a_store_scoped_session(): void
    {
        $store = $this->catalogStore();
        $payload = $this->registrationPayload();
        $initialToken = $this->getJson(route('catalog.account.session', $store))
            ->assertOk()
            ->json('data.csrf_token');

        $registerResponse = $this->postJson(route('catalog.account.register', $store), $payload)
            ->assertCreated()
            ->assertJsonPath('data.authenticated', true)
            ->assertJsonPath('data.customer.email', $payload['email'])
            ->assertJsonStructure(['data' => ['csrf_token']]);
        $this->assertNotSame($initialToken, $registerResponse->json('data.csrf_token'));

        $customer = Customer::where('store_id', $store->id)->where('email', $payload['email'])->firstOrFail();
        $account = CustomerAccount::where('customer_id', $customer->id)->firstOrFail();

        $this->assertTrue(Hash::check($payload['password'], $account->password));
        $this->assertNotNull($account->last_login_at);

        $this->getJson(route('catalog.account.session', $store))
            ->assertOk()
            ->assertJsonPath('data.authenticated', true)
            ->assertJsonPath('data.customer.identification', $payload['identification']);
    }

    public function test_account_cannot_be_used_in_another_store(): void
    {
        $firstStore = $this->catalogStore();
        $secondStore = $this->catalogStore();
        $payload = $this->registrationPayload();

        $this->postJson(route('catalog.account.register', $firstStore), $payload)->assertCreated();

        $this->getJson(route('catalog.account.session', $secondStore))
            ->assertOk()
            ->assertJsonPath('data.authenticated', false);

        $this->postJson(route('catalog.account.login', $secondStore), [
            'email' => $payload['email'],
            'password' => $payload['password'],
        ])->assertUnprocessable();
    }

    public function test_same_email_can_register_in_two_different_stores(): void
    {
        $firstStore = $this->catalogStore();
        $secondStore = $this->catalogStore();
        $payload = $this->registrationPayload();

        $this->postJson(route('catalog.account.register', $firstStore), $payload)->assertCreated();
        $this->postJson(route('catalog.account.logout', $firstStore))->assertOk();

        $payload['identification'] = Str::upper(Str::random(10));
        $this->postJson(route('catalog.account.register', $secondStore), $payload)->assertCreated();

        $this->assertSame(2, CustomerAccount::where('email', $payload['email'])->count());
    }

    public function test_duplicate_customer_data_is_rejected_without_creating_partial_records(): void
    {
        $store = $this->catalogStore();
        $payload = $this->registrationPayload();

        $this->postJson(route('catalog.account.register', $store), $payload)->assertCreated();
        $this->postJson(route('catalog.account.logout', $store))->assertOk();
        $this->postJson(route('catalog.account.register', $store), $payload)
            ->assertUnprocessable();

        $this->assertSame(1, Customer::where('store_id', $store->id)->where('email', $payload['email'])->count());
        $this->assertSame(1, CustomerAccount::where('store_id', $store->id)->where('email', $payload['email'])->count());
    }

    public function test_inactive_account_cannot_log_in(): void
    {
        $store = $this->catalogStore();
        $payload = $this->registrationPayload();

        $this->postJson(route('catalog.account.register', $store), $payload)->assertCreated();
        $this->postJson(route('catalog.account.logout', $store))->assertOk();
        CustomerAccount::where('store_id', $store->id)->where('email', $payload['email'])->update(['is_active' => false]);

        $this->postJson(route('catalog.account.login', $store), [
            'email' => $payload['email'],
            'password' => $payload['password'],
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'El correo o la contraseña no son correctos.');
    }

    public function test_customer_can_recover_password_with_a_single_use_store_scoped_link(): void
    {
        Notification::fake();
        $store = $this->catalogStore();
        $payload = $this->registrationPayload();
        $this->postJson(route('catalog.account.register', $store), $payload)->assertCreated();
        $this->postJson(route('catalog.account.logout', $store))->assertOk();
        $account = CustomerAccount::where('store_id', $store->id)->where('email', $payload['email'])->firstOrFail();

        $this->postJson(route('catalog.account.password.email', $store), ['email' => $payload['email']])
            ->assertOk()
            ->assertJsonPath('message', 'Si el correo pertenece a una cuenta activa, recibirás un enlace para crear una nueva contraseña.');

        $resetUrl = null;
        Notification::assertSentTo($account, CatalogCustomerResetPasswordNotification::class, function ($notification) use (&$resetUrl) {
            $resetUrl = $notification->url;
            return true;
        });
        parse_str((string) parse_url($resetUrl, PHP_URL_QUERY), $query);

        $otherStore = $this->catalogStore();
        $this->postJson(route('catalog.account.password.reset', $otherStore), [
            'email' => $payload['email'],
            'token' => $query['reset_token'],
            'password' => 'NoDebeAplicar123',
            'password_confirmation' => 'NoDebeAplicar123',
        ])->assertUnprocessable();
        $this->assertTrue(Hash::check($payload['password'], $account->fresh()->password));

        $this->postJson(route('catalog.account.password.reset', $store), [
            'email' => $payload['email'],
            'token' => $query['reset_token'],
            'password' => 'NuevaClave123',
            'password_confirmation' => 'NuevaClave123',
        ])->assertOk()
            ->assertJsonPath('message', 'Tu contraseña fue actualizada. Ya puedes iniciar sesión.');

        $this->assertTrue(Hash::check('NuevaClave123', $account->fresh()->password));
        $this->assertDatabaseMissing('customer_password_reset_tokens', [
            'store_id' => $store->id,
            'email' => $payload['email'],
        ]);

        $this->postJson(route('catalog.account.password.reset', $store), [
            'email' => $payload['email'],
            'token' => $query['reset_token'],
            'password' => 'OtraClave123',
            'password_confirmation' => 'OtraClave123',
        ])->assertUnprocessable();
    }

    public function test_password_recovery_does_not_reveal_unknown_accounts(): void
    {
        Notification::fake();
        $store = $this->catalogStore();

        $this->postJson(route('catalog.account.password.email', $store), ['email' => 'desconocido@example.test'])
            ->assertOk()
            ->assertJsonPath('message', 'Si el correo pertenece a una cuenta activa, recibirás un enlace para crear una nueva contraseña.');

        Notification::assertNothingSent();
    }

    public function test_authenticated_checkout_is_linked_and_visible_only_in_customer_history(): void
    {
        $store = $this->catalogStore();
        $warehouse = $store->warehouses()->firstOrFail();
        $product = $this->productWithStock($store, $warehouse, 20);
        $payload = $this->registrationPayload();

        $this->postJson(route('catalog.account.register', $store), $payload)->assertCreated();
        $customer = Customer::where('store_id', $store->id)->where('email', $payload['email'])->firstOrFail();

        $orderResponse = $this->postJson(route('catalog.orders.store', $store), [
            'customer_name' => $payload['name'],
            'customer_phone' => $payload['phone'],
            'fulfillment_type' => 'pickup',
            'payment_method' => 'Efectivo',
            'items' => [[
                'product_id' => $product->id,
                'presentation_id' => null,
                'quantity' => 2,
            ]],
        ])->assertCreated();

        $order = CatalogOrder::where('reference', $orderResponse->json('data.reference'))->firstOrFail();
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertDatabaseHas('catalog_order_status_histories', [
            'catalog_order_id' => $order->id,
            'to_status' => CatalogOrder::PENDING,
        ]);

        $this->getJson(route('catalog.account.orders', $store))
            ->assertOk()
            ->assertJsonPath('data.orders.0.reference', $order->reference)
            ->assertJsonPath('data.orders.0.status', CatalogOrder::PENDING);

        $this->getJson(route('catalog.account.orders.show', [$store, $order]))
            ->assertOk()
            ->assertJsonPath('data.order.items.0.product_name', $product->name)
            ->assertJsonPath('data.order.history.0.status', CatalogOrder::PENDING);
    }

    public function test_guest_cannot_create_an_order_without_authentication(): void
    {
        $store = $this->catalogStore();
        $warehouse = $store->warehouses()->firstOrFail();
        $product = $this->productWithStock($store, $warehouse, 10);

        $this->postJson(route('catalog.orders.store', $store), [
            'customer_name' => 'Invitado',
            'customer_phone' => '0995554444',
            'fulfillment_type' => 'pickup',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertUnauthorized();

        $this->assertSame(0, CatalogOrder::where('store_id', $store->id)->count());
        $this->getJson(route('catalog.account.orders', $store))->assertUnauthorized();
    }

    public function test_customer_session_cannot_create_orders_in_another_store(): void
    {
        $firstStore = $this->catalogStore();
        $secondStore = $this->catalogStore();
        $secondWarehouse = $secondStore->warehouses()->firstOrFail();
        $product = $this->productWithStock($secondStore, $secondWarehouse, 10);

        $this->postJson(route('catalog.account.register', $firstStore), $this->registrationPayload())
            ->assertCreated();

        $this->postJson(route('catalog.orders.store', $secondStore), [
            'customer_name' => 'Cliente cruzado',
            'customer_phone' => '0995554444',
            'fulfillment_type' => 'pickup',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertUnauthorized();

        $this->assertSame(0, CatalogOrder::where('store_id', $secondStore->id)->count());
    }

    public function test_catalog_uses_the_real_presentation_type_name(): void
    {
        $store = $this->catalogStore();
        $warehouse = $store->warehouses()->firstOrFail();
        $product = $this->productWithStock($store, $warehouse, 24);
        $product->update(['manage_presentations' => true]);
        $family = PresentationFamily::create([
            'store_id' => $store->id,
            'name' => 'Cervezas',
            'slug' => 'cervezas-'.Str::lower(Str::random(6)),
        ]);
        $type = PresentationType::create([
            'store_id' => $store->id,
            'presentation_family_id' => $family->id,
            'name' => 'Caja de 24',
            'slug' => 'caja-24-'.Str::lower(Str::random(6)),
            'default_equivalence' => 24,
        ]);
        $presentation = ProductPresentation::create([
            'product_id' => $product->id,
            'presentation_type_id' => $type->id,
            'equivalence' => 24,
            'price' => 20,
            'is_default' => true,
        ]);

        $this->getJson('/api/catalog/'.$store->slug)
            ->assertOk()
            ->assertJsonPath('data.products.0.options.0.presentations.0.name', 'Caja de 24');

        $payload = $this->registrationPayload();
        $this->postJson(route('catalog.account.register', $store), $payload)->assertCreated();
        $response = $this->postJson(route('catalog.orders.store', $store), [
            'customer_name' => $payload['name'],
            'customer_phone' => $payload['phone'],
            'fulfillment_type' => 'pickup',
            'items' => [[
                'product_id' => $product->id,
                'presentation_id' => $presentation->id,
                'quantity' => 1,
            ]],
        ])->assertCreated();

        $order = CatalogOrder::where('reference', $response->json('data.reference'))->firstOrFail();
        $this->assertSame('Caja de 24', $order->items()->value('presentation_name'));
    }

    private function catalogStore(): Store
    {
        $suffix = Str::lower(Str::random(10));
        $organization = Organization::create([
            'name' => "Organización catálogo {$suffix}",
            'slug' => "organizacion-catalogo-{$suffix}",
            'is_active' => true,
        ]);
        $plan = SaaSPlan::create([
            'code' => "catalog-{$suffix}",
            'name' => 'Plan catálogo',
            'price' => 0,
            'currency' => 'USD',
            'billing_interval' => 'month',
            'billing_interval_count' => 1,
            'trial_days' => 0,
            'grace_days' => 0,
            'features' => [],
            'is_active' => true,
        ]);
        OrganizationSubscription::create([
            'organization_id' => $organization->id,
            'saas_plan_id' => $plan->id,
            'status' => OrganizationSubscription::STATUS_ACTIVE,
            'starts_at' => now(),
            'current_period_starts_at' => now(),
            'current_period_ends_at' => now()->addMonth(),
        ]);
        $store = Store::create([
            'organization_id' => $organization->id,
            'name' => "Catálogo {$suffix}",
            'slug' => "catalogo-{$suffix}",
            'is_active' => true,
        ]);
        $warehouse = Warehouse::create([
            'store_id' => $store->id,
            'name' => "Bodega {$suffix}",
            'phone' => '0999999999',
            'country' => 'Ecuador',
            'city' => 'Manta',
            'email' => "bodega-{$suffix}@example.test",
            'zip_code' => '130802',
            'is_active' => true,
        ]);
        CatalogSetting::create([
            'store_id' => $store->id,
            'warehouse_id' => $warehouse->id,
            'is_enabled' => true,
            'whatsapp_number' => '0999999999',
        ]);

        return $store;
    }

    private function productWithStock(Store $store, Warehouse $warehouse, float $quantity): Product
    {
        $suffix = Str::upper(Str::random(8));
        $category = ProductCategory::create(['store_id' => $store->id, 'name' => "Categoría {$suffix}"]);
        $brand = Brand::create(['store_id' => $store->id, 'name' => "Marca {$suffix}"]);
        $unit = BaseUnit::firstOrCreate(['name' => "Unidad {$suffix}"]);
        $product = Product::create([
            'store_id' => $store->id,
            'name' => "Producto {$suffix}",
            'code' => "SKU{$suffix}",
            'product_code' => "BAR{$suffix}",
            'product_category_id' => $category->id,
            'brand_id' => $brand->id,
            'product_cost' => 2,
            'product_price' => 4,
            'product_unit' => (string) $unit->id,
            'stock_alert' => 2,
            'barcode_symbol' => Product::CODE128,
            'catalog_visible' => true,
        ]);
        ManageStock::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
        ]);

        return $product;
    }

    private function registrationPayload(): array
    {
        $suffix = Str::lower(Str::random(10));

        return [
            'tipo_identificacion' => Customer::TIPO_CEDULA,
            'identification' => Str::upper(Str::random(10)),
            'name' => 'Cliente del catálogo',
            'email' => "cliente-{$suffix}@example.test",
            'phone' => '0991234567',
            'country' => 'Ecuador',
            'city' => 'Manta',
            'address' => 'Avenida principal 123',
            'dob' => '1995-05-10',
            'password' => 'Cliente123',
            'password_confirmation' => 'Cliente123',
            'terms' => true,
        ];
    }
}
