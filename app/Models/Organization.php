<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    protected $fillable = [
        'name',
        'slug',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
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
}
