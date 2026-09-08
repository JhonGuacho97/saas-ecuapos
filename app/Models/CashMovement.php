<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CashMovement extends BaseModel
{
    use BelongsToStore;

    public const IN = 'IN';
    public const OUT = 'OUT';
    public const OPENING = 'OPENING';
    public const MANUAL_INCOME = 'MANUAL_INCOME';
    public const MANUAL_EXPENSE = 'MANUAL_EXPENSE';
    public const WITHDRAWAL = 'WITHDRAWAL';
    public const SALE_PAYMENT = 'SALE_PAYMENT';
    public const EXPENSE_PAYMENT = 'EXPENSE_PAYMENT';
    public const CASH_REFUND = 'CASH_REFUND';
    public const REVERSAL = 'REVERSAL';
    public const TRANSFER_IN = 'TRANSFER_IN';
    public const TRANSFER_OUT = 'TRANSFER_OUT';

    protected $fillable = [
        'pos_register_id', 'cash_register_id', 'store_id', 'warehouse_id', 'user_id', 'approved_by',
        'type', 'direction', 'amount', 'balance_after', 'reference', 'description',
        'source_type', 'source_id', 'reversed_movement_id', 'transfer_uuid', 'reversal_reason', 'metadata',
    ];
    protected $casts = ['amount' => 'decimal:4', 'balance_after' => 'decimal:4', 'metadata' => 'array'];

    public function session(): BelongsTo { return $this->belongsTo(POSRegister::class, 'pos_register_id'); }
    public function cashRegister(): BelongsTo { return $this->belongsTo(CashRegister::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function approvedBy(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function reversedMovement(): BelongsTo { return $this->belongsTo(self::class, 'reversed_movement_id'); }
    public function reversal(): HasOne { return $this->hasOne(self::class, 'reversed_movement_id'); }
    public function source(): MorphTo { return $this->morphTo(); }
}
