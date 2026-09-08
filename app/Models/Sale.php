<?php

namespace App\Models;

use App\Models\Contracts\JsonResourceful;
use App\Traits\HasJsonResourcefulData;
use App\Models\User;
use Eloquent;
use App\Models\Concerns\BelongsToStoreThroughWarehouse;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * App\Models\Sale
 *
 * @property int $id
 * @property \Illuminate\Support\Carbon $date
 * @property int $customer_id
 * @property int $warehouse_id
 * @property float|null $tax_rate
 * @property float|null $tax_amount
 * @property float|null $discount
 * @property float|null $shipping
 * @property float|null $grand_total
 * @property float|null $received_amount
 * @property float|null $paid_amount
 * @property int|null $payment_type
 * @property string|null $note
 * @property string|null $reference_code
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int|null $status
 * @property int|null $payment_status
 * @property-read \App\Models\Customer $customer
 * @property-read string $sale_pdf_url
 * @property-read \Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection|Media[] $media
 * @property-read int|null $media_count
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\SaleItem[] $saleItems
 * @property-read int|null $sale_items_count
 * @property-read \App\Models\Warehouse $warehouse
 * @property-read \App\Models\SalesPayment $latestPayment
 * @method static \Illuminate\Database\Eloquent\Builder|Sale newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Sale newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Sale query()
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereCustomerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereDiscount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereGrandTotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereNote($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale wherePaidAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale wherePaymentStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale wherePaymentType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereReceivedAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereReferenceCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereShipping($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereTaxAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereTaxRate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereWarehouseId($value)
 * @property int $is_return
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\SalesPayment[] $payments
 * @property-read int|null $payments_count
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereIsReturn($value)
 * @property float|null $subtotal_sin_iva
 * @property int|null $user_id
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereSubtotalSinIva($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Sale whereUserId($value)
 * @property-read \App\Models\ElectronicInvoice|null $electronicInvoice
 * @mixin Eloquent
 */
class Sale extends BaseModel implements HasMedia, JsonResourceful
{
    use HasFactory, InteractsWithMedia, HasJsonResourcefulData, BelongsToStoreThroughWarehouse;

    protected $table = 'sales';

    public const JSON_API_TYPE = 'sales';

    public const SALE_PDF = 'sale_pdf';

    public const SALE_BARCODE_PATH = 'sale_barcode_path';

    public const CODE128 = 1;

    public const CODE39 = 2;

    public const EAN8 = 3;

    public const UPC = 4;

    public const EAN13 = 5;

    protected $fillable = [
        'client_uuid',
        'date',
        'offline_created_at',
        'created_offline',
        'electronic_invoice_requested_type',
        'electronic_invoice_requested_at',
        'customer_id',
        'warehouse_id',
        'tax_rate',
        'tax_amount',
        'discount',
        'shipping',
        'grand_total',
        'received_amount',
        'paid_amount',
        'payment_type',
        'note',
        'status',
        'payment_status',
        'payment_due_date',
        'payment_terms_days',
        'collection_note',
        'reference_code',
        'barcode_symbol',
        'is_return',
        'user_id',
    ];

    public static $rules = [
        'client_uuid' => 'nullable|uuid|max:36',
        'date' => 'date|required',
        'offline_created_at' => 'nullable|date',
        'created_offline' => 'nullable|boolean',
        'customer_id' => 'required|exists:customers,id',
        'warehouse_id' => 'required|exists:warehouses,id',
        'tax_rate' => 'nullable|numeric|min:0',
        'tax_amount' => 'nullable|numeric|min:0',
        'discount' => 'nullable|numeric|min:0',
        'shipping' => 'nullable|numeric|min:0',
        'grand_total' => 'nullable|numeric',
        'received_amount' => 'numeric|nullable',
        'paid_amount' => 'numeric|nullable',
        'payment_type' => 'numeric|integer',
        'notes' => 'nullable',
        'status' => 'integer|required',
        'payment_status' => 'integer|required',
        'payment_due_date' => 'nullable|date',
        'payment_terms_days' => 'nullable|integer|min:0|max:3650',
        'collection_note' => 'nullable|string|max:2000',
        'reference_code' => 'nullable',
    ];

    public $casts = [
        'date' => 'date',
        'offline_created_at' => 'datetime',
        'created_offline' => 'boolean',
        'electronic_invoice_requested_at' => 'datetime',
        'tax_rate' => 'double',
        'tax_amount' => 'double',
        'discount' => 'double',
        'shipping' => 'double',
        'grand_total' => 'double',
        'received_amount' => 'double',
        'paid_amount' => 'double',
        'payment_status' => 'integer',
        'payment_due_date' => 'date',
        'payment_terms_days' => 'integer',
        'status' => 'integer',
        'payment_type' => 'integer',
    ];

    //tax type  const
    const EXCLUSIVE = 1;

    const INCLUSIVE = 2;

    // discount type const
    const PERCENTAGE = 1;

    const FIXED = 2;

    // payment type
    const CASH = 1;

    const CHEQUE = 2;

    const BANK_TRANSFER = 3;

    const OTHER = 4;

    // Order status
    const COMPLETED = 1;

    const PENDING = 2;

    const ORDERED = 3;

    // payment status
    const PAID = 1;

    const UNPAID = 2;

    const PARTIAL_PAID = 3;

    public function prepareLinks(): array
    {
        return [
            'self' => route('sales.show', $this->id),
        ];
    }

    public function getSalePdfUrlAttribute(): string
    {
        /** @var Media $media */
        $media = $this->getMedia(self::SALE_PDF)->first();
        if (!empty($media)) {
            return $media->getFullUrl();
        }

        return '';
    }

    public function prepareAttributes(): array
    {
        $fields = [
            'client_uuid' => $this->client_uuid,
            'date' => $this->date,
            'offline_created_at' => $this->offline_created_at,
            'created_offline' => $this->created_offline,
            'electronic_invoice_requested_type' => $this->electronic_invoice_requested_type,
            'electronic_invoice_requested_at' => $this->electronic_invoice_requested_at,
            'is_return' => $this->is_return,
            'customer_id' => $this->customer_id,
            'customer_name' => $this->customer->name,
            'customer' => $this->customer,
            'warehouse_id' => $this->warehouse_id,
            'warehouse_name' => $this->warehouse->name,
            'user_id' => $this->user_id,
            'user_name' => $this->user ? trim($this->user->first_name . ' ' . $this->user->last_name) : null,
            'tax_rate' => $this->tax_rate,
            'tax_amount' => $this->tax_amount,
            'discount' => $this->discount,
            'shipping' => $this->shipping,
            'grand_total' => $this->grand_total,
            'received_amount' => $this->received_amount,
            'paid_amount' => $this->paid_amount,
            'due_amount' => $this->dueAmount($this->id),
            'payment_type' => $this->payment_type,
            'payments_count' => $this->payments_count ?? $this->payments()->count(),
            'payments' => $this->payments,
            'note' => $this->note,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'payment_due_date' => optional($this->payment_due_date)->format('Y-m-d'),
            'payment_terms_days' => $this->payment_terms_days,
            'collection_note' => $this->collection_note,
            'reference_code' => $this->reference_code,
            'sale_items' => $this->saleItems,
            'created_at' => $this->created_at,
            'barcode_url' => Storage::url('sales/barcode-' . $this->reference_code . '.png'),
            // Número real del comprobante (ej. "001-001-000050001"), solo
            // disponible una vez que el SRI (o el proceso de emisión)
            // le asignó un secuencial a esta venta -- si todavía no
            // existe (la emisión es asíncrona, puede tardar), queda en
            // null y el frontend cae de vuelta al reference_code.
            'numero_comprobante' => $this->electronicInvoice ? $this->electronicInvoice->numeroComprobante() : null,
            'tipo_comprobante' => $this->electronicInvoice->tipo_comprobante ?? null,
        ];

        return $fields;
    }

    public function prepareRecentSelling(): array
    {
        $fields = [
            'reference_code' => $this->reference_code,
            'customer_name' => $this->customer->name,
            'grand_total' => $this->grand_total,
            'paid_amount' => $this->paid_amount,
            'due_amount' => $this->dueAmount($this->id),
            'payment_status' => $this->payment_status,
            'status' => $this->status,
        ];

        return $fields;
    }

    /**
     * Subtotal sin IVA para el XML del SRI.
     * Si ya tienes subtotal_sin_iva guardado lo usa,
     * si no lo calcula desde grand_total - tax_amount.
     */
    public function subtotalSinIvaSri(): float
    {
        // Suma la base real de cada línea (cada una ya sabe si su
        // propio producto es Inclusivo o Exclusivo) -- usar
        // grand_total - tax_amount (campos globales de la venta, el
        // "Impuesto de pedido" del formulario) daba mal cuando el
        // impuesto real vive a nivel de producto y ese campo global
        // nunca se tocó (quedaba en 0, aunque los productos sí tuvieran
        // IVA configurado).
        $items = $this->saleItems;

        if ($items->isNotEmpty()) {
            return round(
                $items->sum(fn (SaleItem $item) => $item->precioTotalSinImpuestoSri()),
                2
            );
        }

        if (!empty($this->subtotal_sin_iva)) {
            return round($this->subtotal_sin_iva, 2);
        }

        return round(($this->grand_total ?? 0) - ($this->tax_amount ?? 0), 2);
    }

    /**
     * Forma de pago mapeada al código SRI.
     * Basado en las constantes que ya tienes: CASH=1, CHEQUE=2,
     * BANK_TRANSFER=3, OTHER=4.
     */
    public function formaPagoSri(): string
    {
        return match ($this->payment_type) {
            self::CASH => '01', // efectivo
            self::CHEQUE => '15', // transferencia (cheque → más cercano)
            self::BANK_TRANSFER => '15', // transferencia bancaria
            self::OTHER => '01', // otros → efectivo por defecto
            default => '01',
        };
    }

    /**
     * Desglose real de pagos para el XML. Agrupa métodos que comparten el
     * mismo código SRI, limita el total al importe de la factura y conserva
     * compatibilidad con ventas antiguas que no tienen filas de pago.
     */
    public function pagosSri(): array
    {
        $payments = $this->relationLoaded('payments') ? $this->payments : $this->payments()->get();
        $remaining = round((float) $this->grand_total, 2);
        $grouped = [];

        foreach ($payments as $payment) {
            if ($remaining <= 0) {
                break;
            }

            $amount = min(round((float) $payment->amount, 2), $remaining);
            if ($amount <= 0) {
                continue;
            }

            $code = match ((int) $payment->payment_type) {
                self::CASH => '01',
                self::CHEQUE, self::BANK_TRANSFER => '15',
                default => '01',
            };
            $grouped[$code] = round(($grouped[$code] ?? 0) + $amount, 2);
            $remaining = round($remaining - $amount, 2);
        }

        if ($remaining > 0) {
            $code = $this->formaPagoSri();
            $grouped[$code] = round(($grouped[$code] ?? 0) + $remaining, 2);
        }

        return collect($grouped)->map(
            fn ($amount, $code) => ['formaPago' => (string) $code, 'total' => (float) $amount]
        )->values()->all();
    }

    /**
     * Fecha de emisión en formato dd/MM/yyyy que requiere el SRI.
     */
    public function fechaEmisionSri(): string
    {
        return $this->date->format('d/m/Y');
    }

    /**
     * Relación con la factura electrónica generada para esta venta.
     */
    public function electronicInvoice(): HasOne
    {
        // latestOfMany() -- una venta puede terminar con más de un
        // ElectronicInvoice si una emisión se rechaza y se reintenta (ver
        // ElectronicInvoiceController::reintentar(), que ya NO borra el
        // registro rechazado para no perder el secuencial/historial). Sin
        // esto, un hasOne() plano devuelve el primero por id (el
        // rechazado viejo) en vez del más reciente.
        return $this->hasOne(ElectronicInvoice::class, 'sale_id', 'id')->latestOfMany();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id', 'id');
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class, 'sale_id', 'id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalesPayment::class, 'sale_id', 'id');
    }

    public function collectionActivities(): HasMany
    {
        return $this->hasMany(CollectionActivity::class, 'sale_id', 'id');
    }

    public function latestCollectionActivity(): HasOne
    {
        return $this->hasOne(CollectionActivity::class, 'sale_id', 'id')->latestOfMany('contacted_at');
    }

    /**
     * @return int|mixed
     */
    public function dueAmount($id)
    {
        $grandTotal = Sale::whereId($id)->value('grand_total');
        $paidAmount = SalesPayment::whereSaleId($id)->sum('amount');
        $creditedAmount = $this->creditedAmount($id);

        return max(0, round($grandTotal - $paidAmount - $creditedAmount, 2));
    }

    /** Importe de notas de crédito válidas aplicado a la venta. */
    public function creditedAmount(?int $id = null): float
    {
        return (float) CreditNote::where('sale_id', $id ?? $this->id)
            ->where('generar_como', CreditNote::GENERAR_SALDO)
            ->noCanceladas()
            ->where(function ($q) {
                $q->whereDoesntHave('electronicInvoice')
                    ->orWhereHas('electronicInvoice', function ($qe) {
                        $qe->whereNotIn('estado', [
                            ElectronicInvoice::NO_AUTORIZADA,
                            ElectronicInvoice::DEVUELTA,
                        ]);
                    });
            })
            ->sum('grand_total');
    }

    /** Recalcula el estado visible de pago desde sus movimientos reales. */
    public function refreshPaymentStatus(): self
    {
        $payments = round((float) $this->payments()->sum('amount'), 2);
        $settled = round($payments + $this->creditedAmount(), 2);
        $total = round((float) $this->grand_total, 2);

        $this->forceFill([
            'paid_amount' => $payments,
            'payment_status' => $settled >= $total
                ? self::PAID
                : ($payments > 0 ? self::PARTIAL_PAID : self::UNPAID),
        ])->save();

        return $this;
    }
}
