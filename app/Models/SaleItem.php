<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStoreThroughParent;
use App\Models\Contracts\JsonResourceful;
use App\Traits\HasJsonResourcefulData;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\ProductPresentation;

/**
 * App\Models\SaleItem
 *
 * @property int $id
 * @property int $sale_id
 * @property int $product_id
 * @property float|null $product_price
 * @property float|null $net_unit_price
 * @property int $tax_type
 * @property float|null $tax_value
 * @property float|null $tax_amount
 * @property int $discount_type
 * @property float|null $discount_value
 * @property float|null $discount_amount
 * @property int $sale_unit
 * @property float|null $quantity
 * @property float|null $sub_total
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Product $product
 * @property-read \App\Models\Sale $sale
 * @method static \Illuminate\Database\Eloquent\Builder|SaleItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|SaleItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|SaleItem query()
 * @method static \Illuminate\Database\Eloquent\Builder|SaleItem whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleItem whereDiscountAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleItem whereDiscountType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleItem whereDiscountValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleItem whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleItem whereNetUnitPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleItem whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleItem whereProductPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleItem whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleItem whereSaleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleItem whereSaleUnit($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleItem whereSubTotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleItem whereTaxAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleItem whereTaxType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleItem whereTaxValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SaleItem whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class SaleItem extends BaseModel implements JsonResourceful
{
    use BelongsToStoreThroughParent, HasFactory, HasJsonResourcefulData;

    protected $table = 'sale_items';

    protected function storeParentRelationName(): string
    {
        return 'sale';
    }

    public const JSON_API_TYPE = 'sales_items';

    protected $fillable = [
        'product_id',
        'product_presentation_id',
        'presentation_quantity',
        'presentation_equivalence',
        'product_price',
        'unit_cost',
        'total_cost',
        'cost_is_estimated',
        'net_unit_price',
        'tax_type',
        'tax_value',
        'tax_amount',
        'discount_type',
        'discount_value',
        'discount_amount',
        'sale_unit',
        'quantity',
        'sub_total',
    ];

    public static $rules = [
        'product_id' => 'required|exists:products,id',
        'product_price' => 'nullable|numeric',
        'tax_type' => 'nullable|numeric',
        'tax_value' => 'nullable|numeric',
        'tax_amount' => 'nullable|numeric',
        'discount_type' => 'nullable|numeric',
        'discount_value' => 'nullable|numeric',
        'discount_amount' => 'nullable|numeric',
        'sale_unit' => 'nullable|numeric',
        'quantity' => 'required|numeric|min:0.01',
        'sub_total' => 'nullable|numeric',
    ];

    public $casts = [
        'product_price' => 'double',
        'unit_cost' => 'double',
        'total_cost' => 'double',
        'cost_is_estimated' => 'boolean',
        'tax_amount' => 'double',
        'tax_value' => 'double',
        'discount_value' => 'double',
        'discount_amount' => 'double',
        'quantity' => 'double',
        'presentation_quantity' => 'double',
        'presentation_equivalence' => 'double',
        'sub_total' => 'double',
    ];

    public function getSaleUnitAttribute($value): array
    {
        $saleUnit = Unit::whereId($value)->first();
        if ($saleUnit) {
            return $saleUnit->toArray();
        }

        return [];
    }

    public function prepareLinks(): array
    {
        return [

        ];
    }

    public function prepareAttributes(): array
    {
        $fields = [
            'product_id' => $this->product_id,
            'product_presentation_id' => $this->product_presentation_id,
            'presentation_quantity' => $this->presentation_quantity,
            'presentation_equivalence' => $this->presentation_equivalence,
            'net_unit_price' => $this->net_unit_price,
            'product_price' => $this->product_price,
            'unit_cost' => $this->unit_cost,
            'total_cost' => $this->total_cost,
            'cost_is_estimated' => $this->cost_is_estimated,
            'tax_type' => $this->tax_type,
            'tax_value' => $this->tax_value,
            'tax_amount' => $this->tax_amount,
            'discount_type' => $this->discount_type,
            'discount_value' => $this->discount_value,
            'discount_amount' => $this->discount_amount,
            'sale_unit' => $this->sale_unit,
            'quantity' => $this->quantity,
            'sub_total' => $this->sub_total,
        ];

        return $fields;
    }

    /**
     * Precio unitario neto para el XML.
     * Usa net_unit_price; si es IVA inclusivo lo descuenta.
     */
    public function precioUnitarioSri(): float
    {
        // net_unit_price YA viene neto (sin IVA) desde SaleRepository --
        // ahí se resta el IVA una vez si el tipo es Inclusivo, antes de
        // guardar. Restarlo de nuevo acá era una doble resta (bug real,
        // confirmado con un caso real: $10 con 15% Inclusivo daba
        // $7.56 en vez de $8.70).
        return round($this->net_unit_price, 6);
    }

    /**
     * Precio total sin impuesto para el XML (<precioTotalSinImpuesto>).
     * sub_total siempre viene bruto (con IVA incluido) desde
     * SaleRepository::calculationSaleItems(), tanto para Inclusivo como
     * para Exclusivo -- tax_amount es exactamente la porción de IVA dentro
     * de ese bruto en ambos casos, así que restarlo da la base imponible
     * real sin volver a derivar la tarifa (bug real, confirmado con un
     * caso real: $10 con 15% Exclusivo devolvía $11.50 de base en vez de
     * $10.00, porque el caso Exclusivo nunca restaba el IVA del bruto).
     */
    public function precioTotalSinImpuestoSri(): float
    {
        return round($this->sub_total - $this->tax_amount, 2);
    }

    /**
     * Valor IVA calculado para el XML (<valor> dentro de <impuesto>).
     */
    public function valorIvaSri(): float
    {
        if ($this->tax_amount > 0) {
            return round((float) $this->tax_amount, 2);
        }

        // Calcular con la tarifa de la venta si el item no tiene
        $base = $this->precioTotalSinImpuestoSri();
        $tarifa = ($this->tax_value > 0)
            ? $this->tax_value
            : ($this->sale->tax_rate ?? 0);

        return round($base * $tarifa / 100, 2);
    }

    /**
     * Código de porcentaje IVA para el XML según tabla 17 del SRI.
     */
    public function codigoPorcentajeIvaSri(): string
    {
        // Si el item no tiene tarifa definida, toma la de la venta
        $tarifa = ($this->tax_value > 0)
            ? $this->tax_value
            : ($this->sale->tax_rate ?? 0);

        return match ((int) $tarifa) {
            0 => '0',
            12 => '2',
            14 => '3',
            15 => '4',
            5 => '5',
            default => '4',
        };
    }

    public function tarifaIvaSri(): float
    {
        return ($this->tax_value > 0)
            ? (float) $this->tax_value
            : (float) ($this->sale->tax_rate ?? 0);
    }

    /**
     * Código del producto para el XML (<codigoPrincipal>).
     */
    public function codigoPrincipalSri(): string
    {
        return $this->product->code ?? 'S/C';
    }

    /**
     * Descripción del producto para el XML.
     */
    public function descripcionSri(): string
    {
        return strtoupper($this->product->name ?? 'PRODUCTO');
    }

    /**
     * Descuento en valor absoluto para el XML.
     */
    public function descuentoSri(): float
    {
        return round($this->discount_amount ?? 0, 2);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id', 'id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }
    public function productPresentation(): BelongsTo
    {
        return $this->belongsTo(
            ProductPresentation::class,
            'product_presentation_id',
            'id'
        );
    }
}
