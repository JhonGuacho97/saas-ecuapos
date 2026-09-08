<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use App\Models\Contracts\JsonResourceful;
use App\Traits\HasJsonResourcefulData;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

class CreditNote extends BaseModel implements JsonResourceful
{
    use BelongsToStore, HasFactory, HasJsonResourcefulData;

    public const JSON_API_TYPE = 'credit_notes';

    // ── Conceptos (deciden si se toca stock) ──────
    const CONCEPTO_DEVOLUCION = 'POR_DEVOLUCION';
    const CONCEPTO_DESCUENTO = 'POR_DESCUENTO';
    const CONCEPTO_CORRECCION_PRECIO = 'POR_CORRECCION_PRECIO';
    const CONCEPTO_ERROR_FACTURACION = 'POR_ERROR_FACTURACION';
    const CONCEPTO_OTRO = 'OTRO';

    // Solo este concepto ajusta stock -- los demás son ajustes
    // puramente contables/de facturación, sin movimiento de mercadería.
    const CONCEPTOS_QUE_TOCAN_STOCK = [self::CONCEPTO_DEVOLUCION];

    // ── Generar como ───────────────────────────────
    const GENERAR_SALDO = 'SALDO';
    const GENERAR_ANTICIPO = 'ANTICIPO';

    // ── Estado ──────────────────────────────────────
    // Antes 'status' se guardaba pero nunca se leía en ningún lado (el
    // frontend mandaba un 2 fijo al crear, sin significado real). Ahora
    // se usa para marcar notas canceladas -- ver
    // CreditNoteRepository::cancelarCreditNote().
    const STATUS_ACTIVA = 1;
    const STATUS_CANCELADA = 0;

    protected $fillable = [
        'date',
        'sale_id',
        'tipo_comprobante_modificado',
        'numero_comprobante_modificado',
        'customer_id',
        'warehouse_id',
        'credit_note_category_id',
        'vendedor',
        'generar_como',
        'concepto',
        'motivo',
        'tax_rate',
        'tax_amount',
        'discount',
        'shipping',
        'grand_total',
        'status',
        'note',
        'reference_code',
    ];

    protected static function booted(): void
    {
        static::creating(function (CreditNote $creditNote) {
            $saleStoreId = $creditNote->sale_id
                ? Sale::acrossStores()->whereKey($creditNote->sale_id)->value('store_id')
                : null;

            if ($saleStoreId === null) {
                throw new RuntimeException('La nota de crédito no puede guardarse sin una venta que resuelva su tienda.');
            }

            if ($creditNote->store_id !== null && (int) $creditNote->store_id !== (int) $saleStoreId) {
                throw new RuntimeException('La tienda de la nota de crédito no coincide con la venta original.');
            }

            if (currentStoreId() !== null && (int) currentStoreId() !== (int) $saleStoreId) {
                throw new RuntimeException('La venta original no pertenece a la tienda activa.');
            }

            $creditNote->store_id = $saleStoreId;
        });
    }

    protected $casts = [
        'date' => 'date',
        'tax_rate' => 'double',
        'tax_amount' => 'double',
        'discount' => 'double',
        'shipping' => 'double',
        'grand_total' => 'double',
    ];

    public static $rules = [
        'date' => 'required',
        'sale_id' => 'required|exists:sales,id',
        'customer_id' => 'required|exists:customers,id',
        'concepto' => 'required|string',
        'generar_como' => 'required|string',
        'motivo' => 'required|string',
    ];

    // ── Relaciones ─────────────────────────────────

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id', 'id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id', 'id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CreditNoteCategory::class, 'credit_note_category_id', 'id');
    }

    public function creditNoteItems(): HasMany
    {
        return $this->hasMany(CreditNoteItem::class, 'credit_note_id', 'id');
    }

    public function electronicInvoice(): HasOne
    {
        // Mismo criterio que Sale::electronicInvoice() -- ver ese comentario.
        return $this->hasOne(ElectronicInvoice::class, 'credit_note_id', 'id')->latestOfMany();
    }

    /**
     * Suma la base real de cada línea (igual criterio que
     * Sale::subtotalSinIvaSri() -- cada item ya sabe si su producto es
     * Inclusivo o Exclusivo, no depende de un campo global).
     */
    public function subtotalSinIvaSri(): float
    {
        $items = $this->creditNoteItems;

        if ($items->isNotEmpty()) {
            return round(
                $items->sum(fn (CreditNoteItem $item) => $item->precioTotalSinImpuestoSri()),
                2
            );
        }

        return round(($this->grand_total ?? 0) - ($this->tax_amount ?? 0), 2);
    }

    // ── Helpers ────────────────────────────────────

    public function tocaStock(): bool
    {
        return in_array($this->concepto, self::CONCEPTOS_QUE_TOCAN_STOCK);
    }

    public function estaCancelada(): bool
    {
        // NULL (filas creadas antes de esta feature) se trata como
        // ACTIVA, no como cancelada -- mismo criterio que scopeNoCanceladas().
        return $this->status !== null && (int) $this->status === self::STATUS_CANCELADA;
    }

    /**
     * Excluye notas canceladas de una query. OJO: 'status' es nullable
     * y todas las notas creadas antes de este cambio quedaron con
     * status=2 (o NULL en filas muy viejas) -- un simple
     * where('status', '!=', STATUS_CANCELADA) descartaría esas filas
     * NULL de la suma (en SQL, NULL != 0 no es true), tratándolas como
     * si estuvieran canceladas sin estarlo. Este scope trata NULL como
     * "activa", que es lo correcto para datos previos a esta feature.
     */
    public function scopeNoCanceladas($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('status')->orWhere('status', '!=', self::STATUS_CANCELADA);
        });
    }

    /**
     * Una nota se puede cancelar solo si nunca llegó a ser un
     * comprobante fiscal válido ante el SRI -- si está autorizada o
     * todavía en trámite (pendiente/recibida), cancelarla dejaría un
     * comprobante fiscal sin respaldo en el sistema.
     */
    public function puedeCancelarse(): bool
    {
        if ($this->estaCancelada()) {
            return false;
        }

        $ei = $this->electronicInvoice;

        return !$ei || $ei->estaRechazada() || $ei->esErrorTemporalSri();
    }

    public function prepareLinks(): array
    {
        return [];
    }

    public function prepareAttributes(): array
    {
        return [
            'date' => optional($this->date)->format('Y-m-d'),
            'sale_id' => $this->sale_id,
            'tipo_comprobante_modificado' => $this->tipo_comprobante_modificado,
            'numero_comprobante_modificado' => $this->numero_comprobante_modificado,
            'customer_id' => $this->customer_id,
            'customer_name' => $this->customer?->name,
            'customer' => $this->customer,
            'warehouse_id' => $this->warehouse_id,
            'warehouse_name' => $this->warehouse?->name,
            'credit_note_category_id' => $this->credit_note_category_id,
            'category_name' => $this->category?->name,
            'vendedor' => $this->vendedor,
            'generar_como' => $this->generar_como,
            'concepto' => $this->concepto,
            'motivo' => $this->motivo,
            'tax_rate' => $this->tax_rate,
            'tax_amount' => $this->tax_amount,
            'discount' => $this->discount,
            'shipping' => $this->shipping,
            'grand_total' => $this->grand_total,
            'status' => $this->status,
            'esta_cancelada' => $this->estaCancelada(),
            'puede_cancelarse' => $this->puedeCancelarse(),
            'note' => $this->note,
            'reference_code' => $this->reference_code,
            'credit_note_items' => $this->creditNoteItems,
            'created_at' => optional($this->created_at)->format('Y-m-d H:i:s'),
        ];
    }
}
