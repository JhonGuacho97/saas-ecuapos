<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Cliente SaaS y frontera superior de aislamiento. Una organización puede
 * operar varias tiendas, mientras cada tienda conserva sus propios datos.
 */
class Organization extends BaseModel
{
    use HasFactory;

    public const ROLE_OWNER = 'OWNER';
    public const ROLE_ADMIN = 'ADMIN';
    public const ROLE_MEMBER = 'MEMBER';

    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_INVITED = 'INVITED';
    public const STATUS_SUSPENDED = 'SUSPENDED';

    public const SUSPENSION_BILLING = 'BILLING';
    public const SUSPENSION_ADMINISTRATIVE = 'ADMINISTRATIVE';
    public const SUSPENSION_SECURITY = 'SECURITY';

    protected $fillable = [
        'name',
        'slug',
        'is_active',
        'suspension_reason',
        'suspended_at',
        'suspension_note',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'suspended_at' => 'datetime',
    ];

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_user')
            ->withPivot(['role', 'status'])
            ->withTimestamps();
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(OrganizationSubscription::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SaaSPayment::class);
    }
}
