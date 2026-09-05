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
    public const STATUS_PAST_DUE = 'PAST_DUE';
    public const STATUS_CANCELED = 'CANCELED';

    protected $fillable = [
        'organization_id',
        'saas_plan_id',
        'status',
        'auto_renew',
        'cancel_at_period_end',
        'starts_at',
        'trial_ends_at',
        'current_period_starts_at',
        'current_period_ends_at',
        'canceled_at',
        'grace_ends_at',
        'last_payment_at',
        'next_billing_at',
        'payment_provider',
        'provider_customer_id',
        'provider_subscription_id',
        'electronic_documents_used',
        'admin_notes',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'current_period_starts_at' => 'datetime',
        'current_period_ends_at' => 'datetime',
        'auto_renew' => 'boolean',
        'cancel_at_period_end' => 'boolean',
        'canceled_at' => 'datetime',
        'grace_ends_at' => 'datetime',
        'last_payment_at' => 'datetime',
        'next_billing_at' => 'datetime',
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

    public function payments(): HasMany
    {
        return $this->hasMany(SaaSPayment::class, 'organization_subscription_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SaaSSubscriptionEvent::class, 'organization_subscription_id');
    }
}
