<?php

namespace Tests\Feature;

use App\Models\BaseUnit;
use App\Models\Brand;
use App\Models\ManageStock;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class InventoryReportsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_stock_report_returns_operational_summary_and_statuses(): void
    {
        [$store, $warehouse, $user] = $this->context();
        $this->productWithStock($store, $warehouse, 'Producto bajo', 4, 10, 2.5, 5);
        $this->productWithStock($store, $warehouse, 'Producto disponible', 20, 5, 3, 7);
        Sanctum::actingAs($user, ['*']);

        $this->withHeader('X-Store-Id', $store->id)
            ->getJson('/api/stock-report?warehouse_id='.$warehouse->id.'&page[size]=10&page[number]=1')
            ->assertOk()
            ->assertJsonPath('summary.products', 2)
            ->assertJsonPath('summary.critical', 1)
            ->assertJsonPath('summary.healthy', 1)
            ->assertJsonPath('summary.cost_value', 70)
            ->assertJsonCount(2, 'data');
    }

    public function test_stock_alerts_are_calculated_live_from_current_threshold(): void
    {
        [$store, $warehouse, $user] = $this->context();
        $product = $this->productWithStock($store, $warehouse, 'Alerta viva', 8, 5, 1, 2);
        // Simula que el usuario cambió el mínimo sin que existiera un nuevo
        // movimiento de stock que refrescara manage_stocks.alert.
        $product->update(['stock_alert' => 10]);
        Sanctum::actingAs($user, ['*']);

        $this->withHeader('X-Store-Id', $store->id)
            ->getJson('/api/product-stock-alerts/'.$warehouse->id.'?page[size]=10&page[number]=1')
            ->assertOk()
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('data.0.name', 'Alerta viva')
            ->assertJsonPath('data.0.shortage', 2);
    }

    public function test_stock_report_rejects_a_warehouse_from_another_store(): void
    {
        [$store, , $user] = $this->context();
        [, $foreignWarehouse] = $this->context();
        setPermissionsTeamId($store->id);
        $user->unsetRelation('permissions')->unsetRelation('roles');
        Sanctum::actingAs($user, ['*']);

        $this->withHeader('X-Store-Id', $store->id)
            ->getJson('/api/stock-report?warehouse_id='.$foreignWarehouse->id)
            ->assertForbidden()
            ->assertJsonPath('message', 'La bodega no pertenece a la tienda activa o se encuentra desactivada.');
    }

    private function context(): array
    {
        $suffix = Str::lower(Str::random(10));
        $store = Store::create(['name' => "Inventario {$suffix}", 'slug' => "inventario-{$suffix}", 'is_active' => true]);
        $warehouse = Warehouse::create([
            'store_id' => $store->id,
            'name' => "Bodega {$suffix}",
            'phone' => '0999999999',
            'country' => 'Ecuador',
            'city' => 'Manta',
            'email' => "warehouse-{$suffix}@example.test",
        ]);
        $user = User::create([
            'first_name' => 'Inventario',
            'last_name' => 'Tester',
            'email' => "inventory-{$suffix}@example.test",
            'phone' => '0988888888',
            'password' => bcrypt('secret123'),
            'status' => true,
        ]);
        $user->stores()->attach($store->id);
        setPermissionsTeamId($store->id);
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'manage_reports', 'guard_name' => 'web']));

        return [$store, $warehouse, $user];
    }

    private function productWithStock(Store $store, Warehouse $warehouse, string $name, float $quantity, float $minimum, float $cost, float $price): Product
    {
        $suffix = Str::upper(Str::random(8));
        $category = ProductCategory::create(['store_id' => $store->id, 'name' => "Categoría {$suffix}"]);
        $brand = Brand::create(['store_id' => $store->id, 'name' => "Marca {$suffix}"]);
        $unit = BaseUnit::firstOrCreate(['name' => "Unidad {$suffix}"]);
        $product = Product::create([
            'store_id' => $store->id,
            'name' => $name,
            'code' => "SKU{$suffix}",
            'product_code' => "BAR{$suffix}",
            'product_category_id' => $category->id,
            'brand_id' => $brand->id,
            'product_cost' => $cost,
            'product_price' => $price,
            'product_unit' => (string) $unit->id,
            'warehouse_id' => $warehouse->id,
            'stock_alert' => $minimum,
            'barcode_symbol' => Product::CODE128,
        ]);
        ManageStock::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => $quantity]);

        return $product;
    }
}
