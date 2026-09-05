<?php

namespace App\Models;

use App\Models\Contracts\JsonResourceful;
use App\Traits\HasJsonResourcefulData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Models\POSRegister
 *
 * @property int $id
 * @property float $cash_in_hand
 * @property \Illuminate\Support\Carbon|null $closed_at
 * @property float|null $cash_in_hand_while_closing
 * @property float|null $bank_transfer
 * @property float|null $cheque
 * @property float|null $other
 * @property int $user_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder|POSRegister newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|POSRegister newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|POSRegister query()
 * @method static \Illuminate\Database\Eloquent\Builder|POSRegister whereBankTransfer($value)
 * @method static \Illuminate\Database\Eloquent\Builder|POSRegister whereCashInHand($value)
 * @method static \Illuminate\Database\Eloquent\Builder|POSRegister whereCashInHandWhileClosing($value)
 * @method static \Illuminate\Database\Eloquent\Builder|POSRegister whereCheque($value)
 * @method static \Illuminate\Database\Eloquent\Builder|POSRegister whereClosedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|POSRegister whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|POSRegister whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|POSRegister whereOther($value)
 * @method static \Illuminate\Database\Eloquent\Builder|POSRegister whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|POSRegister whereUserId($value)
 * @property float|null $total_sale
 * @property float|null $total_return
 * @property float|null $total_amount
 * @property string|null $notes
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder|POSRegister whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder|POSRegister whereTotalAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|POSRegister whereTotalReturn($value)
 * @method static \Illuminate\Database\Eloquent\Builder|POSRegister whereTotalSale($value)
 * @mixin \Eloquent
 */
class POSRegister extends BaseModel implements JsonResourceful
{
    use HasFactory, HasJsonResourcefulData;

    protected $table = 'pos_register';

    public $fillable = [
        'cash_in_hand',
        'opening_denominations',
        'closed_at',
        'closed_by',
        'cash_in_hand_while_closing',
        'expected_cash',
        'cash_difference',
        'closing_denominations',
        'discrepancy_reason',
        'discrepancy_note',
        'reconciliation_status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'bank_transfer',
        'cheque',
        'other',
        'total_sale',
        'total_return',
        'total_amount',
        'notes',
        'user_id',
        'warehouse_id',
        'cash_register_id',
    ];

    public $casts = [
        'closed_at' => 'datetime',
        'cash_in_hand_while_closing' => 'double',
        'expected_cash' => 'double',
        'cash_difference' => 'double',
        'opening_denominations' => 'array',
        'closing_denominations' => 'array',
        'reviewed_at' => 'datetime',
        'bank_transfer' => 'double',
        'cheque' => 'double',
        'other' => 'double',
        'notes' => 'string',
        'total_sale' => 'double',
        'total_return' => 'double',
        'total_amount' => 'double',
    ];

    public static $rules = [
        'cash_in_hand' => 'required|numeric',
        'opening_denominations' => 'nullable|array',
        'closing_denominations' => 'nullable|array',
    ];

    /**
     * @return string[]
     */
    public function getIdFilterFields(): array
    {
        return [
            'id' => self::class,
        ];
    }

    public function prepareLinks(): array
    {
        return [];
    }

    public function prepareAttributes(): array
    {
        $fields = [
            'cash_in_hand_while_closing' => $this->cash_in_hand_while_closing,
            'expected_cash' => $this->expected_cash,
            'cash_difference' => $this->cash_difference,
            'cash_in_hand' => $this->cash_in_hand,
            'opening_denominations' => $this->opening_denominations,
            'closing_denominations' => $this->closing_denominations,
            'discrepancy_reason' => $this->discrepancy_reason,
            'discrepancy_note' => $this->discrepancy_note,
            'reconciliation_status' => $this->reconciliation_status,
            'reviewed_by' => $this->reviewedBy,
            'reviewed_at' => $this->reviewed_at,
            'review_note' => $this->review_note,
            'notes' => $this->notes,
            'closed_at' => $this->closed_at,
            'created_at' => $this->created_at,
            'user' => $this->user,
            'warehouse_id' => $this->warehouse_id,
            'cash_register' => $this->cashRegister,
            'warehouse' => $this->warehouse,
            'closed_by' => $this->closedBy,
            'bank_transfer' => $this->bank_transfer,
            'cheque' => $this->cheque,
            'other' => $this->other,
            'total_sale' => $this->total_sale,
            'total_return' => $this->total_return,
            'total_amount' => $this->total_amount,
            'duration_minutes' => $this->closed_at && $this->created_at
                ? $this->created_at->diffInMinutes($this->closed_at)
                : null,
        ];

        return $fields;
    }

    /**
     * Get the user that owns the POSRegister
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Sucursal donde se abrió esta caja. Nullable por ahora -- hasta la
     * Fase 1, pos_register nunca guardó esta información; se completa
     * retroactivamente en la Fase 2 (back-fill inferido de las ventas de
     * la sesión) y se vuelve obligatorio para cajas nuevas recién en la
     * Fase 8.
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id', 'id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function movements()
    {
        return $this->hasMany(CashMovement::class, 'pos_register_id');
    }

    /**
     * Aísla los turnos por la tienda de su almacén. Si no existe un
     * contexto de tienda válido, falla cerrado para no exponer una caja
     * perteneciente a otra sucursal.
     */
    public function scopeForStore(Builder $query, ?int $storeId): Builder
    {
        if (! $storeId) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('warehouse', fn (Builder $warehouse) => $warehouse
            ->where('store_id', $storeId)
            ->active());
    }

    public function scopeOpenForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId)->whereNull('closed_at');
    }
}
