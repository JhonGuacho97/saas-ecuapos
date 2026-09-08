<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStoreThroughParent;
use App\Models\Contracts\JsonResourceful;
use App\Traits\HasJsonResourcefulData;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditNoteItem extends BaseModel implements JsonResourceful
{
    use BelongsToStoreThroughParent, HasFactory, HasJsonResourcefulData;

    protected $table = 'credit_note_items';

    protected function storeParentRelationName(): string
    {
        return 'creditNote';
    }

    public const JSON_API_TYPE = 'credit_note_items';

    protected $fillable = [
        'credit_note_id',
        'product_id',
        'product_presentation_id',
        'presentation_quantity',
        'presentation_equivalence',
        'product_price',
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

    protected $casts = [
        'product_price' => 'double',
        'net_unit_price' => 'double',
        'tax_amount' => 'double',
        'tax_value' => 'double',
        'discount_value' => 'double',
        'discount_amount' => 'double',
        'quantity' => 'double',
        'presentation_quantity' => 'double',
        'presentation_equivalence' => 'double',
        'sub_total' => 'double',
    ];

    public static $rules = [
        'product_id' => 'required|exists:products,id',
        'quantity' => 'required|numeric|min:0.01',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class, 'credit_note_id', 'id');
    }

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
        return [];
    }

    public function prepareAttributes(): array
    {
        return [
            'product_id' => $this->product_id,
            'net_unit_price' => $this->net_unit_price,
            'product_price' => $this->product_price,
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
    }

    // ── Helpers SRI (calcados de SaleItem, misma lógica exacta) ──

    public function precioUnitarioSri(): float
    {
        // Mismo criterio que SaleItem::precioUnitarioSri() -- net_unit_price
        // ya viene neto (sin IVA), no hay que restarlo de nuevo acá.
        return round($this->net_unit_price, 6);
    }

    public function precioTotalSinImpuestoSri(): float
    {
        // Mismo fix que SaleItem::precioTotalSinImpuestoSri() -- sub_total
        // es siempre bruto (con IVA) y tax_amount es la porción de IVA
        // dentro de ese bruto, para Inclusivo y Exclusivo por igual.
        return round($this->sub_total - $this->tax_amount, 2);
    }

    public function valorIvaSri(): float
    {
        if ($this->tax_amount > 0) {
            return round((float) $this->tax_amount, 2);
        }

        $base = $this->precioTotalSinImpuestoSri();
        $tarifa = ($this->tax_value > 0)
            ? $this->tax_value
            : ($this->creditNote->tax_rate ?? 0);

        return round($base * $tarifa / 100, 2);
    }

    public function codigoPorcentajeIvaSri(): string
    {
        $tarifa = ($this->tax_value > 0)
            ? $this->tax_value
            : ($this->creditNote->tax_rate ?? 0);

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
            : (float) ($this->creditNote->tax_rate ?? 0);
    }

    public function codigoPrincipalSri(): string
    {
        return $this->product->code ?? 'S/C';
    }

    public function descripcionSri(): string
    {
        return strtoupper($this->product->name ?? 'PRODUCTO');
    }

    public function descuentoSri(): float
    {
        return round($this->discount_amount ?? 0, 2);
    }
}
