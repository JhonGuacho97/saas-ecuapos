<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PresentationFamily extends BaseModel
{
    use BelongsToStore;

    protected $fillable = [
        'store_id',
        'name',
        'slug',
        'is_active',
        'sort',
    ];

    protected $casts = [
        'store_id' => 'integer',
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function types(): HasMany
    {
        return $this->hasMany(PresentationType::class, 'presentation_family_id')
            ->orderBy('sort')
            ->orderBy('name');
    }
}
