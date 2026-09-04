<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationSubscription extends BaseModel
{
    public const STATUS_TRIALING = 'TRIALING';
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_EXPIRED = 'EXPIRED';
    public const STATUS_SUSPENDED = 'SUSPENDED';

    protected $fillable = [
        'organization_id',
        'saas_plan_id',
        'status',
        'starts_at',
        'trial_ends_at',
        'current_period_starts_at',
        'current_period_ends_at',
        'electronic_documents_used',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'current_period_starts_at' => 'datetime',
        'current_period_ends_at' => 'datetime',
        'electronic_documents_used' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SaaSPlan::class, 'saas_plan_id');
    }

    public function usageReservations(): HasMany
    {
        return $this->hasMany(SaaSUsageReservation::class, 'organization_subscription_id');
    }
}
