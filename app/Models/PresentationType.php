<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PresentationType extends BaseModel
{
    use BelongsToStore;

    protected $fillable = [
        'store_id',
        'presentation_family_id',
        'name',
        'slug',
        'default_equivalence',
        'is_active',
        'sort',
    ];

    protected $casts = [
        'store_id' => 'integer',
        'presentation_family_id' => 'integer',
        'default_equivalence' => 'float',
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    public function family(): BelongsTo
    {
        return $this->belongsTo(PresentationFamily::class, 'presentation_family_id');
    }

    public function productPresentations(): HasMany
    {
        return $this->hasMany(ProductPresentation::class, 'presentation_type_id');
    }
}
