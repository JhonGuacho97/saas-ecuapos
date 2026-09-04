<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaaSUsageReservation extends BaseModel
{
    public const METRIC_ELECTRONIC_DOCUMENTS = 'electronic_documents';

    protected $table = 'saas_usage_reservations';

    protected $fillable = [
        'organization_subscription_id',
        'metric',
        'source_type',
        'source_id',
        'reserved_at',
    ];

    protected $casts = [
        'reserved_at' => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(OrganizationSubscription::class, 'organization_subscription_id');
    }
}
