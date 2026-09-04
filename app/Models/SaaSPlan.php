<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class SaaSPlan extends BaseModel
{
    protected $table = 'saas_plans';

    protected $fillable = [
        'code',
        'name',
        'trial_days',
        'max_users',
        'max_stores',
        'max_warehouses',
        'max_electronic_documents',
        'features',
        'is_active',
    ];

    protected $casts = [
        'trial_days' => 'integer',
        'max_users' => 'integer',
        'max_stores' => 'integer',
        'max_warehouses' => 'integer',
        'max_electronic_documents' => 'integer',
        'features' => 'array',
        'is_active' => 'boolean',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(OrganizationSubscription::class, 'saas_plan_id');
    }
}
