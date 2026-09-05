<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaaSPayment extends BaseModel
{
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_PAID = 'PAID';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_REFUNDED = 'REFUNDED';

    protected $table = 'saas_payments';

    protected $fillable = [
        'organization_id', 'organization_subscription_id', 'saas_plan_id',
        'amount', 'currency', 'status', 'method', 'provider', 'provider_reference',
        'period_starts_at', 'period_ends_at', 'paid_at', 'failed_at',
        'failure_reason', 'metadata', 'recorded_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'period_starts_at' => 'datetime',
        'period_ends_at' => 'datetime',
        'paid_at' => 'datetime',
        'failed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function subscription(): BelongsTo { return $this->belongsTo(OrganizationSubscription::class, 'organization_subscription_id'); }
    public function plan(): BelongsTo { return $this->belongsTo(SaaSPlan::class, 'saas_plan_id'); }
    public function recordedBy(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
}
