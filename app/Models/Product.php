<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;

use App\Models\Contracts\JsonResourceful;
use App\Traits\HasJsonResourcefulData;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * App\Models\Product
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string $product_code
 * @property int $product_category_id
 * @property int $brand_id
 * @property int $main_product_id
 * @property float $product_cost
 * @property float $product_price
 * @property string $product_unit
 * @property string|null $sale_unit
 * @property string|null $purchase_unit
 * @property int $warehouse_id
 * @property string|null $stock_alert
 * @property float|null $order_tax
 * @property string|null $tax_type
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Brand|null $brand
 * @property-read \App\Models\MainProduct|null $mainProduct
 * @property-read string $image_url
 * @property-read \Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection|Media[] $media
 * @property-read int|null $media_count
 * @property-read \App\Models\ProductCategory|null $productCategory
 * @property-read \App\Models\Warehouse|null $warehouse
 * @method static \Illuminate\Database\Eloquent\Builder|Product newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Product newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Product query()
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereBrandId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereMainProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereProductCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereOrderTax($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereProductCategoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereProductCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereProductPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereProductUnit($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product wherePurchaseUnit($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereSaleUnit($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereStockAlert($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereTaxType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereWarehouseId($value)
 * @property-read string $barcode_image_url
 * @property int|null $barcode_symbol
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereBarcodeSymbol($value)
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Purchase[] $purchases
 * @property-read int|null $purchases_count
 * @property-read \App\Models\ManageStock|null $stock
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\ManageStock[] $stocks
 * @property-read int|null $stocks_count
 * @property string|null $quantity_limit
 * @method static \Illuminate\Database\Eloquent\Builder|Product whereQuantityLimit($value)
 * @property-read \App\Models\VariationProduct|null $variationProduct
 * @property-read \App\Models\VariationType|null $variationType
 * @mixin \Eloquent
 */
class Product extends BaseModel implements HasMedia, JsonResourceful
{
    use HasFactory, InteractsWithMedia, HasJsonResourcefulData, BelongsToStore;

    protected $table = 'products';

    const JSON_API_TYPE = 'products';

    public const PATH = 'product';

    public const PRODUCT_BARCODE_PATH = 'product_barcode';

    public const CODE128 = 1;

    public const CODE39 = 2;

    public const EAN8 = 3;

    public const UPC = 4;

    public const EAN13 = 5;

    protected $appends = ['image_url', 'barcode_image_url'];

    protected $fillable = [
        'store_id',
        'name',
        'code',
        'product_code',
        'product_category_id',
        'main_product_id',
        'brand_id',
        'product_cost',
        'product_price',
        'product_unit',
        'sale_unit',
        'purchase_unit',
        'stock_alert',
        'quantity_limit',
        'order_tax',
        'tax_type',
        'notes',
        'barcode_symbol',
        'manage_presentations',
        'is_kit',
        'catalog_visible',
        'catalog_featured',
        'catalog_description',
    ];

    public static $rules = [
        'name' => 'required',
        'code' => 'required|unique:products',
        'product_code' => 'required',
        'product_category_id' => 'required|exists:product_categories,id',
        'brand_id' => 'required|exists:brands,id',
        'product_cost' => 'required|numeric',
        'product_price' => 'required|numeric',
        'product_unit' => 'required',
        'sale_unit' => 'nullable',
        'purchase_unit' => 'nullable',
        'stock_alert' => 'nullable',
        'quantity_limit' => 'nullable',
        'order_tax' => 'nullable|numeric',
        'tax_type' => 'nullable',
        'notes' => 'nullable',
        'barcode_symbol' => 'required',
        'images.*' => 'image|mimes:jpg,jpeg,png',
        'catalog_visible' => 'nullable|boolean',
        'catalog_featured' => 'nullable|boolean',
        'catalog_description' => 'nullable|string|max:1000',
    ];

    public static $availableRelations = [
        'product_category_id' => 'productCategory',
        'brand_id' => 'brand',
    ];

    protected $casts = [
        'product_cost' => 'float',
        'product_price' => 'float',
        'grand_total' => 'float',
        'order_tax' => 'float',
        'manage_presentations' => 'boolean',
        'is_kit' => 'boolean',
        'catalog_visible' => 'boolean',
        'catalog_featured' => 'boolean',
    ];

    /**
     * @return array|string
     */
    public function getImageUrlAttribute()
    {
        /** @var Media $media */
        // OJO: $medias es una Collection -- !empty() sobre un objeto SIEMPRE
        // da true en PHP (empty() solo evalúa valores realmente falsy:
        // 0, '', null, false, [], "0"), así que esta condición entraba
        // siempre al branch del foreach aunque la colección viniera vacía,
        // y terminaba devolviendo [] en vez de '' cuando el producto no
        // tiene imagen. isNotEmpty() sí revisa el contenido real.
        $medias = $this->getMedia(Product::PATH);
        $images = [];
        if ($medias->isNotEmpty()) {
            foreach ($medias as $key => $media) {
                $images['imageUrls'][$key] = $media->getFullUrl();
                $images['id'][$key] = $media->id;
            }

            return $images;
        }

        return '';
    }

    public function getBarcodeImageUrlAttribute(): string
    {
        /** @var Media $media */
        $media = $this->getMedia(Product::PRODUCT_BARCODE_PATH)->first();
        if (!empty($media)) {
            return $media->getFullUrl();
        }

        return '';
    }

    public function prepareLinks(): array
    {
        return [
            'self' => route('products.show', $this->id),
        ];
    }

    public function prepareAttributes(): array
    {
        $this->load('variationProduct','mainProduct');

        // Un kit no guarda su propio stock real -- lo que muestra el
        // catálogo/POS siempre se recalcula en vivo desde sus componentes
        // (buildableQuantity()), nunca se confía en el valor ya guardado
        // en manage_stocks (que solo existe para que la query del listado
        // pueda encontrar/filtrar el kit por bodega, ver syncKitStock()).
        $requestWarehouseId = request()->get('warehouse_id');
        if ($this->is_kit) {
            if ($this->relationLoaded('stock') && $this->stock) {
                $this->stock->quantity = $this->buildableQuantity($this->stock->warehouse_id);
            }
            $inStockKit = $requestWarehouseId
                ? $this->buildableQuantity((int) $requestWarehouseId)
                : ManageStock::where('product_id', $this->id)->get()
                    ->sum(fn ($row) => $this->buildableQuantity($row->warehouse_id));
        }

        $fields = [
            'name' => $this->name,
            'code' => $this->code,
            'product_code' => $this->product_code,
            'main_product_id' => $this->main_product_id,
            'product_category_id' => $this->product_category_id,
            'brand_id' => $this->brand_id,
            'product_cost' => $this->product_cost,
            'product_price' => $this->product_price,
            'effective_price' => $this->priceForWarehouse(request()->get('warehouse_id')),
            'product_unit' => $this->product_unit,
            'sale_unit' => $this->sale_unit,
            'purchase_unit' => $this->purchase_unit,
            'stock_alert' => $this->stock_alert,
            'quantity_limit' => $this->quantity_limit,
            'order_tax' => $this->order_tax,
            'tax_type' => $this->tax_type,
            'notes' => $this->notes,
            'catalog_visible' => $this->catalog_visible,
            'catalog_featured' => $this->catalog_featured,
            'catalog_description' => $this->catalog_description,
            // Un kit no tiene main_product_id (no participa del sistema
            // de variaciones) -- antes esto asumía que todo producto
            // tenía un MainProduct y tiraba un error fatal al intentar
            // leer image_url de null.
            'images' => $this->mainProduct?->image_url ?? '',
            'product_category_name' => $this->productCategory?->name,
            'brand_name' => $this->brand?->name,
            'barcode_image_url' => $this->barcode_image_url,
            'barcode_symbol' => $this->barcode_symbol,
            'created_at' => $this->created_at,
            'product_unit_name' => $this->getProductUnitName(),
            'purchase_unit_name' => $this->getPurchaseUnitName(),
            'sale_unit_name' => $this->getSaleUnitName(),
            'stock' => $this->stock,
            'warehouse' => $this->warehouse($this->id) ?? '',
            'barcode_url' => Storage::url('product_barcode/barcode-PR_' . $this->id . '.png'),
            // Si la lista ya trae el total pre-calculado (withSum, una sola
            // consulta para toda la página) se usa ese -- si no (ej. al ver
            // el detalle de un producto suelto), cae al cálculo de siempre.
            // Para un kit, ninguno de los dos aplica -- se reemplaza por la
            // cantidad armable en vivo calculada arriba.
            'in_stock' => $this->is_kit ? $inStockKit : ($this->stocks_sum_quantity ?? $this->inStock($this->id)),
        ];

        $fields['is_kit'] = $this->is_kit;
        if ($this->is_kit) {
            $fields['kit_items'] = $this->kitItems->map(fn (ProductKitItem $item) => $item->prepareAttributes())->values();
        }

        if ($this->variationProduct) {
            $fields['variation_product'] = $this->variationProduct->prepareAttributes();
        }

        $fields['manage_presentations'] = $this->manage_presentations;
        if ($this->manage_presentations) {
            $fields['presentations'] = $this->presentations->map(fn ($p) => $p->prepareAttributes())->values();
        }

        return $fields;
    }

    /**
     * @return string[]
     */
    public function getIdFilterFields(): array
    {
        return [
            'id' => self::class,
            'product_category_id' => ProductCategory::class,
            'brand_id' => Brand::class,
        ];
    }

    /**
     * Traen TODAS las unidades de una sola vez la primera vez que se
     * necesitan, y de ahí en más se leen de memoria -- antes cada producto
     * de la lista disparaba su propia consulta (3 consultas × N productos).
     * Estáticas a propósito: se resetean solas en cada petición nueva
     * (cada request de PHP arranca con la clase "limpia").
     *
     * @return array|string
     */
    public function getProductUnitName()
    {
        static $baseUnits = null;
        if ($baseUnits === null) {
            $baseUnits = BaseUnit::all()->keyBy('id');
        }

        $productUnit = $baseUnits->get($this->product_unit);

        return $productUnit ? $productUnit->toArray() : '';
    }

    /**
     * @return array|string
     */
    public function getPurchaseUnitName()
    {
        static $units = null;
        if ($units === null) {
            $units = Unit::all()->keyBy('id');
        }

        $purchaseUnit = $units->get($this->purchase_unit);

        return $purchaseUnit ? $purchaseUnit->toArray() : '';
    }

    /**
     * @return array|string
     */
    public function getSaleUnitName()
    {
        static $units = null;
        if ($units === null) {
            $units = Unit::all()->keyBy('id');
        }

        $saleUnit = $units->get($this->sale_unit);

        return $saleUnit ? $saleUnit->toArray() : '';
    }

    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id', 'id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id', 'id');
    }

    public function stock(): HasOne
    {
        return $this->hasOne(ManageStock::class, 'product_id', 'id');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class, 'purchase_id', 'id');
    }

    public function prepareTopSelling(): array
    {
        return [
            'name' => $this->name,
            'variation_type_name' => optional($this->variationType)->name,
            'total_quantity' => $this->total_quantity,
            'grand_total' => $this->grand_total,
            'sale_unit' => isset($this->getSaleUnitName()['short_name']) ? $this->getSaleUnitName()['short_name'] : null,
            'image' => $this->image_url,
        ];
    }

    public function prepareProducts(): array
    {
        $imageUrls = $this->mainProduct->image_url;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'product_code' => $this->product_code,
            'price' => $this->product_price,
            'sale_unit' => array_values($this->getProductUnitName())[1],
            'remaining_quantity' => $this->stock->quantity ?? 0,
            'images' => $imageUrls['imageUrls'] ?? [],
        ];
    }

    public function prepareTopSellingReport(): array
    {
        return [
            'name' => $this->name,
            'variation_type_name' => optional($this->variationType)->name,
            'total_quantity' => $this->total_quantity,
            'price' => $this->product_price,
            'grand_total' => $this->grand_total,
            'code' => $this->code,
            'product_code' => $this->product_code,
            'sale_unit' => isset($this->getSaleUnitName()['short_name']) ? $this->getSaleUnitName()['short_name'] : null,
        ];
    }

    public function yearlyTopSelling(): array
    {
        return [
            'name' => $this->name,
            'variation_type_name' => optional($this->variationType)->name,
            'total_quantity' => $this->total_quantity,
            'grand_total' => $this->grand_total,
            'sale_unit' => isset($this->getSaleUnitName()['short_name']) ? $this->getSaleUnitName()['short_name'] : null,
        ];
    }

    /**
     * @return mixed
     */
    public function warehouse($id)
    {
        return Managestock::where('product_id', $id)->Join(
            'warehouses',
            'manage_stocks.warehouse_id',
            'warehouses.id'
        )->where('warehouses.is_active', true)->select(
            DB::raw('sum(quantity) as total_quantity'),
            'manage_stocks.warehouse_id',
            'warehouses.name'
        )->groupBy('warehouse_id')->get();
    }

    /**
     * @return mixed
     */
    public function inStock($id)
    {
        $totalQuantity = Managestock::where('product_id', $id)
            ->whereHas('warehouse', fn ($query) => $query->active())
            ->sum('quantity');

        return $totalQuantity;
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(ManageStock::class, 'product_id', 'id');
    }

    public function mainProduct(): BelongsTo
    {
        return $this->belongsTo(MainProduct::class, 'main_product_id', 'id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id', 'id');
    }

    public function prepareProductReport()
    {
        return [
            'reference_code' => $this->code,
            'name' => $this->name,
            'total_quantity' => $this->total_quantity,
            'grand_total' => $this->grand_total,
            'product_unit' => $this->product_unit,
        ];
    }

    public function variationProduct(): HasOne
    {
        return $this->hasOne(VariationProduct::class, 'product_id', 'id');
    }

    public function variationType(): HasOneThrough
    {
        return $this->hasOneThrough(VariationType::class, VariationProduct::class, 'product_id', 'id', 'id', 'variation_type_id');
    }

    public function presentations(): HasMany
    {
        return $this->hasMany(ProductPresentation::class, 'product_id', 'id')->orderBy('sort');
    }

    /**
     * Componentes reales que forman este producto cuando es_kit=true (ej.
     * "Pack Cerveza + Michelada" = 1 caja de cerveza + 1 michelada). Un kit
     * no tiene stock propio -- ver resolverMovimientoStock()/buildableQuantity().
     */
    public function kitItems(): HasMany
    {
        return $this->hasMany(ProductKitItem::class, 'kit_product_id', 'id');
    }

    public function warehousePrices(): HasMany
    {
        return $this->hasMany(ProductWarehousePrice::class, 'product_id', 'id');
    }

    /**
     * Precio efectivo del producto (base) para una sucursal dada.
     * Si no hay override para esa sucursal, cae al precio general.
     */
    public function priceForWarehouse(?int $warehouseId): float
    {
        if (empty($warehouseId)) {
            return (float) $this->product_price;
        }

        if ($this->relationLoaded('warehousePrices')) {
            $override = $this->warehousePrices->firstWhere('warehouse_id', $warehouseId);
        } else {
            $override = $this->warehousePrices()->where('warehouse_id', $warehouseId)->first();
        }

        return $override ? (float) $override->price : (float) $this->product_price;
    }

    /**
     * Convierte una cantidad vendida/comprada en una presentación específica
     * a unidades base de inventario (lo único que se descuenta/suma en manage_stocks).
     *
     * Ej: convertToBaseUnits(2, $cajaPresentationId) => 2 * 24 = 48
     *
     * Si el producto no maneja presentaciones, o no se pasa presentación,
     * la cantidad ya se asume en unidades base (comportamiento actual, intacto).
     */
    public function convertToBaseUnits(float $quantity, ?int $presentationId = null): float
    {
        if (!$this->manage_presentations || !$presentationId) {
            return $quantity;
        }

        $presentation = $this->presentations()->whereId($presentationId)->first();

        if (!$presentation) {
            throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException(
                'Presentación inválida para el producto ' . $this->name
            );
        }

        return $quantity * $presentation->equivalence;
    }

    /**
     * Traduce una cantidad de ESTE producto (ya en unidades base, con
     * signo: positivo = sumar stock, negativo = restar) a la lista real de
     * productos que hay que tocar en manage_stocks. Para un producto
     * normal es él mismo tal cual; para un kit, sus componentes según la
     * receta de product_kit_items, multiplicados por la cantidad de kits.
     *
     * Este es el único lugar donde vive la lógica de "qué significa
     * vender/devolver un kit" -- todo lo que hoy toca manage_stocks para
     * una línea de venta/devolución/nota de crédito pasa por acá antes de
     * escribir, así que un kit se comporta como cualquier producto desde
     * la perspectiva de esos flujos (no hace falta un camino aparte).
     *
     * @return array<int, array{product_id:int, quantity:float}>
     */
    public function resolverMovimientoStock(float $baseQuantity): array
    {
        if (!$this->is_kit) {
            return [['product_id' => $this->id, 'quantity' => $baseQuantity]];
        }

        $items = $this->relationLoaded('kitItems') ? $this->kitItems : $this->kitItems()->get();

        return $items->map(function (ProductKitItem $item) use ($baseQuantity) {
            // item->quantity está en "unidades de la presentación elegida"
            // cuando la receta especificó una (ej. 4 "Cajas" de Heineken);
            // se multiplica por la equivalencia para descontar stock real
            // en unidades base, igual que hace una venta con presentación.
            $equivalencia = $item->component_product_presentation_id
                ? (float) ($item->presentation?->equivalence ?? 1)
                : 1;

            return [
                'product_id' => $item->component_product_id,
                'quantity' => $item->quantity * $equivalencia * $baseQuantity,
            ];
        })->all();
    }

    /**
     * Cuántos kits se pueden armar HOY con el stock real de sus
     * componentes en una bodega puntual (el mínimo entre "cuántos de este
     * componente alcanzan" para cada componente de la receta). Para un
     * producto normal, es simplemente su stock real.
     *
     * Se calcula en vivo (no se confía en manage_stocks.quantity del
     * propio kit, que es solo una copia de esta cuenta para que el
     * catálogo lo pueda listar/filtrar por bodega -- ver syncKitStock())
     * para que nunca quede desactualizado aunque algo modifique el stock
     * de un componente por otro camino (compra, ajuste, transferencia).
     */
    public function buildableQuantity(int $warehouseId): float
    {
        if (!$this->is_kit) {
            return (float) (ManageStock::where('warehouse_id', $warehouseId)
                ->where('product_id', $this->id)
                ->value('quantity') ?? 0);
        }

        $items = $this->relationLoaded('kitItems') ? $this->kitItems : $this->kitItems()->get();

        if ($items->isEmpty()) {
            return 0;
        }

        $minimo = null;
        foreach ($items as $item) {
            if ($item->quantity <= 0) {
                continue;
            }
            // Misma equivalencia que resolverMovimientoStock() -- cuánto
            // stock (en unidades base) consume 1 unidad del kit para esta
            // línea de receta.
            $equivalencia = $item->component_product_presentation_id
                ? (float) ($item->presentation?->equivalence ?? 1)
                : 1;
            $consumoPorKit = $item->quantity * $equivalencia;
            if ($consumoPorKit <= 0) {
                continue;
            }
            $stockComponente = (float) (ManageStock::where('warehouse_id', $warehouseId)
                ->where('product_id', $item->component_product_id)
                ->value('quantity') ?? 0);
            $armables = floor($stockComponente / $consumoPorKit);
            $minimo = $minimo === null ? $armables : min($minimo, $armables);
        }

        return max(0.0, (float) ($minimo ?? 0));
    }

    /**
     * Deja una fila real en manage_stocks para el kit con la cantidad
     * armable actual -- necesaria únicamente para que el catálogo (que
     * filtra productos con whereHas('stock', ...) por bodega) pueda
     * listar/encontrar el kit en el POS. El número mostrado en el
     * catálogo puede quedar un paso desactualizado hasta la próxima venta
     * de un componente que dispare este sync, pero la validación real al
     * momento de vender siempre recalcula en vivo vía buildableQuantity(),
     * así que nunca se puede vender de más por esta desactualización.
     */
    public function syncKitStock(?int $warehouseId = null): void
    {
        if (!$this->is_kit) {
            return;
        }

        $componentIds = $this->kitItems()->pluck('component_product_id');

        $warehouseIds = $warehouseId
            ? collect([$warehouseId])
            : ManageStock::whereIn('product_id', $componentIds)->distinct()->pluck('warehouse_id');

        foreach ($warehouseIds as $wid) {
            ManageStock::updateOrCreate(
                ['product_id' => $this->id, 'warehouse_id' => $wid],
                ['quantity' => $this->buildableQuantity($wid)]
            );
        }
    }
}
