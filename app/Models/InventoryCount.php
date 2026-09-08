<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryCount extends BaseModel
{
    use BelongsToStore;

    public const DRAFT = 'draft';
    public const COUNTING = 'counting';
    public const REVIEW = 'review';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'store_id', 'warehouse_id', 'product_category_id', 'reference_code',
        'status', 'blind_count', 'notes', 'cancel_reason', 'created_by', 'submitted_by',
        'approved_by', 'cancelled_by', 'adjustment_id', 'submitted_at', 'approved_at',
        'cancelled_at',
    ];

    protected $casts = [
        'blind_count' => 'boolean',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function items(): HasMany { return $this->hasMany(InventoryCountItem::class); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function store(): BelongsTo { return $this->belongsTo(Store::class); }
    public function category(): BelongsTo { return $this->belongsTo(ProductCategory::class, 'product_category_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function submitter(): BelongsTo { return $this->belongsTo(User::class, 'submitted_by'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function canceller(): BelongsTo { return $this->belongsTo(User::class, 'cancelled_by'); }
    public function adjustment(): BelongsTo { return $this->belongsTo(Adjustment::class); }
}
