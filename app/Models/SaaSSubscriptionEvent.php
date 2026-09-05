<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaaSSubscriptionEvent extends BaseModel
{
    protected $table = 'saas_subscription_events';
    protected $fillable = ['organization_subscription_id', 'type', 'description', 'context', 'performed_by'];
    protected $casts = ['context' => 'array'];

    public function subscription(): BelongsTo { return $this->belongsTo(OrganizationSubscription::class, 'organization_subscription_id'); }
    public function performedBy(): BelongsTo { return $this->belongsTo(User::class, 'performed_by'); }
}
