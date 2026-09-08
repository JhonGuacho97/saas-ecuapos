<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogOrder extends BaseModel
{
    use BelongsToStore;

    public const PENDING = 'pending';
    public const CONFIRMED = 'confirmed';
    public const PREPARING = 'preparing';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::PENDING, self::CONFIRMED, self::PREPARING, self::COMPLETED, self::CANCELLED,
    ];

    public const TRANSITIONS = [
        self::PENDING => [self::CONFIRMED, self::CANCELLED],
        self::CONFIRMED => [self::PREPARING, self::CANCELLED],
        self::PREPARING => [self::COMPLETED, self::CANCELLED],
        self::COMPLETED => [],
        self::CANCELLED => [],
    ];

    protected $fillable = [
        'store_id', 'warehouse_id', 'customer_id', 'sale_id', 'reference', 'status', 'assigned_to', 'customer_name',
        'customer_phone', 'fulfillment_type', 'delivery_address',
        'payment_method', 'notes', 'internal_notes', 'subtotal', 'delivery_fee', 'grand_total',
        'whatsapp_opened_at', 'confirmed_at', 'preparing_at', 'completed_at', 'cancelled_at',
    ];

    protected $casts = [
        'subtotal' => 'float',
        'delivery_fee' => 'float',
        'grand_total' => 'float',
        'whatsapp_opened_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'preparing_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CatalogOrderItem::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(CatalogOrderStatusHistory::class)->oldest();
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }
}
