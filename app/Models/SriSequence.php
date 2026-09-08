<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SriSequence extends BaseModel
{
    use BelongsToStore;

    protected $fillable = [
        'store_id',
        'ambiente',
        'estab',
        'pto_emi',
        'tipo_comprobante',
        'ultimo_secuencial',
        'updated_by',
    ];

    protected $casts = [
        'ambiente' => 'integer',
        'ultimo_secuencial' => 'integer',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(SriSequenceAdjustment::class);
    }
}
