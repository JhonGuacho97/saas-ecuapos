<?php

namespace Tests\Feature;

use App\Models\Adjustment;
use App\Models\AdjustmentItem;
use App\Models\BaseUnit;
use App\Models\Brand;
use App\Models\ManageStock;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Store;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Contrato del trait BelongsToStore. Es la red que permite aplicarlo a
 * más modelos sin volver a razonar cada vez qué hace exactamente.
 */
class StoreScopeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_scoped_model_only_returns_rows_of_the_active_store(): void
    {
        [$first, $second] = $this->twoStores();
        $ours = $this->brand($first->id, 'Marca propia');
        $theirs = $this->brand($second->id, 'Marca ajena');

        $this->actingOnStore($first->id);

        $visible = Brand::whereIn('id', [$ours->id, $theirs->id])->pluck('id')->all();
        $this->assertSame([$ours->id], $visible);
        $this->assertNull(Brand::find($theirs->id));
    }

    public function test_crossing_stores_has_to_be_explicit(): void
    {
        [$first, $second] = $this->twoStores();
        $ours = $this->brand($first->id, 'Marca propia');
        $theirs = $this->brand($second->id, 'Marca ajena');

        $this->actingOnStore($first->id);

        $visible = Brand::acrossStores()->whereIn('id', [$ours->id, $theirs->id])
            ->orderBy('id')->pluck('id')->all();
        $this->assertSame([$ours->id, $theirs->id], $visible);
    }

    public function test_new_rows_inherit_the_active_store(): void
    {
        [$first] = $this->twoStores();
        $this->actingOnStore($first->id);

        $brand = Brand::create(['name' => 'Marca sin store_id explícito '.Str::random(6)]);

        $this->assertSame($first->id, (int) $brand->fresh()->store_id);
    }

    public function test_a_declared_store_wins_over_the_active_one(): void
    {
        [$first, $second] = $this->twoStores();
        $this->actingOnStore($first->id);

        $brand = Brand::create([
            'store_id' => $second->id,
            'name' => 'Marca creada para otra tienda '.Str::random(6),
        ]);

        // El autorrelleno cubre el olvido, no pisa una decisión explícita:
        // seeders y comandos de mantenimiento dependen de poder escribir
        // en una tienda que no es la del request.
        $this->assertSame($second->id, (int) $brand->fresh()->store_id);
    }

    public function test_without_an_active_store_nothing_is_filtered(): void
    {
        [$first, $second] = $this->twoStores();
        $ours = $this->brand($first->id, 'Marca propia');
        $theirs = $this->brand($second->id, 'Marca ajena');

        request()->attributes->remove('current_store_id');

        // Comportamiento deliberado: jobs de cola, comandos de consola y
        // el super admin corren sin tienda resuelta y filtran por su
        // cuenta. Ver el comentario de BelongsToStore.
        $visible = Brand::whereIn('id', [$ours->id, $theirs->id])->orderBy('id')->pluck('id')->all();
        $this->assertSame([$ours->id, $theirs->id], $visible);
    }

    public function test_transactional_rows_take_their_store_from_the_warehouse(): void
    {
        [$first] = $this->twoStores();
        $warehouse = $this->warehouse($first->id);

        // Sin request resuelto -- como corren los jobs del SRI y la
        // sincronización offline.
        request()->attributes->remove('current_store_id');
        $adjustment = Adjustment::create([
            'date' => now()->toDateString(),
            'warehouse_id' => $warehouse->id,
            'total_products' => 0,
        ]);

        $this->assertSame($first->id, (int) $adjustment->fresh()->store_id);
    }

    public function test_a_transactional_row_without_a_resolvable_store_is_refused(): void
    {
        request()->attributes->remove('current_store_id');

        $this->expectException(\RuntimeException::class);
        Adjustment::create([
            'date' => now()->toDateString(),
            'warehouse_id' => null,
            'total_products' => 0,
        ]);
    }

    public function test_transactional_rows_are_scoped_like_the_rest(): void
    {
        [$first, $second] = $this->twoStores();
        request()->attributes->remove('current_store_id');
        $ours = Adjustment::create([
            'date' => now()->toDateString(),
            'warehouse_id' => $this->warehouse($first->id)->id,
            'total_products' => 0,
        ]);
        $theirs = Adjustment::create([
            'date' => now()->toDateString(),
            'warehouse_id' => $this->warehouse($second->id)->id,
            'total_products' => 0,
        ]);

        $this->actingOnStore($first->id);

        $visible = Adjustment::whereIn('id', [$ours->id, $theirs->id])->pluck('id')->all();
        $this->assertSame([$ours->id], $visible);
    }

    public function test_child_rows_are_scoped_through_their_parent(): void
    {
        [$first, $second] = $this->twoStores();
        $firstWarehouse = $this->warehouse($first->id);
        $secondWarehouse = $this->warehouse($second->id);
        $firstProduct = $this->product($first, $firstWarehouse);
        $secondProduct = $this->product($second, $secondWarehouse);

        request()->attributes->remove('current_store_id');
        $ours = AdjustmentItem::create([
            'adjustment_id' => Adjustment::create([
                'date' => now()->toDateString(), 'warehouse_id' => $firstWarehouse->id, 'total_products' => 1,
            ])->id,
            'product_id' => $firstProduct->id,
            'method_type' => AdjustmentItem::METHOD_ADDITION,
            'quantity' => 1,
        ]);
        $theirs = AdjustmentItem::create([
            'adjustment_id' => Adjustment::create([
                'date' => now()->toDateString(), 'warehouse_id' => $secondWarehouse->id, 'total_products' => 1,
            ])->id,
            'product_id' => $secondProduct->id,
            'method_type' => AdjustmentItem::METHOD_ADDITION,
            'quantity' => 1,
        ]);

        $this->actingOnStore($first->id);

        $this->assertSame(
            [$ours->id],
            AdjustmentItem::whereIn('id', [$ours->id, $theirs->id])->pluck('id')->all()
        );
        $this->assertSame(
            [$ours->id, $theirs->id],
            AdjustmentItem::acrossStores()->whereIn('id', [$ours->id, $theirs->id])->orderBy('id')->pluck('id')->all()
        );
    }

    public function test_a_child_cannot_be_attached_to_a_parent_from_another_store(): void
    {
        [$first, $second] = $this->twoStores();
        $firstWarehouse = $this->warehouse($first->id);
        $secondWarehouse = $this->warehouse($second->id);
        $firstProduct = $this->product($first, $firstWarehouse);
        request()->attributes->remove('current_store_id');
        $foreignAdjustment = Adjustment::create([
            'date' => now()->toDateString(), 'warehouse_id' => $secondWarehouse->id, 'total_products' => 1,
        ]);

        $this->actingOnStore($first->id);
        $this->expectException(\RuntimeException::class);

        AdjustmentItem::create([
            'adjustment_id' => $foreignAdjustment->id,
            'product_id' => $firstProduct->id,
            'method_type' => AdjustmentItem::METHOD_ADDITION,
            'quantity' => 1,
        ]);
    }

    public function test_manage_stock_is_scoped_through_its_warehouse(): void
    {
        [$first, $second] = $this->twoStores();
        $firstWarehouse = $this->warehouse($first->id);
        $secondWarehouse = $this->warehouse($second->id);
        $ours = ManageStock::create([
            'warehouse_id' => $firstWarehouse->id,
            'product_id' => $this->product($first, $firstWarehouse)->id,
            'quantity' => 5,
        ]);
        $theirs = ManageStock::create([
            'warehouse_id' => $secondWarehouse->id,
            'product_id' => $this->product($second, $secondWarehouse)->id,
            'quantity' => 7,
        ]);

        $this->actingOnStore($first->id);

        $this->assertSame([$ours->id], ManageStock::whereIn('id', [$ours->id, $theirs->id])->pluck('id')->all());
    }

    private function warehouse(int $storeId): Warehouse
    {
        request()->attributes->remove('current_store_id');

        return Warehouse::create([
            'store_id' => $storeId,
            'name' => 'Bodega '.Str::random(6),
            'phone' => '0999999999',
            'country' => 'Ecuador',
            'city' => 'Manta',
            'is_active' => true,
        ]);
    }

    private function actingOnStore(int $storeId): void
    {
        request()->attributes->set('current_store_id', $storeId);
    }

    private function product(Store $store, Warehouse $warehouse): Product
    {
        request()->attributes->remove('current_store_id');
        $suffix = Str::upper(Str::random(8));
        $category = ProductCategory::create(['store_id' => $store->id, 'name' => "Categoría {$suffix}"]);
        $brand = Brand::create(['store_id' => $store->id, 'name' => "Marca {$suffix}"]);
        $unit = BaseUnit::firstOrCreate(['name' => "Unidad {$suffix}"]);

        return Product::create([
            'store_id' => $store->id,
            'name' => "Producto {$suffix}",
            'code' => "SKU{$suffix}",
            'product_code' => "BAR{$suffix}",
            'product_category_id' => $category->id,
            'brand_id' => $brand->id,
            'product_cost' => 1,
            'product_price' => 2,
            'product_unit' => (string) $unit->id,
            'warehouse_id' => $warehouse->id,
            'stock_alert' => 1,
            'barcode_symbol' => Product::CODE128,
        ]);
    }

    /** @return array{0: Store, 1: Store} */
    private function twoStores(): array
    {
        $suffix = Str::lower(Str::random(8));
        $organization = Organization::create([
            'name' => "Organización scope {$suffix}",
            'slug' => "scope-{$suffix}",
            'is_active' => true,
        ]);

        return [
            Store::create([
                'organization_id' => $organization->id,
                'name' => 'Tienda A', 'slug' => "scope-a-{$suffix}", 'is_active' => true,
            ]),
            Store::create([
                'organization_id' => $organization->id,
                'name' => 'Tienda B', 'slug' => "scope-b-{$suffix}", 'is_active' => true,
            ]),
        ];
    }

    private function brand(int $storeId, string $name): Brand
    {
        request()->attributes->remove('current_store_id');

        return Brand::create(['store_id' => $storeId, 'name' => $name.' '.Str::random(6)]);
    }
}
