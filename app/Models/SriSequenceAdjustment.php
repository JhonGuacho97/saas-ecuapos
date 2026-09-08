<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SriSequenceAdjustment extends BaseModel
{
    use BelongsToStore;

    protected $fillable = [
        'sri_sequence_id',
        'store_id',
        'user_id',
        'secuencial_anterior',
        'secuencial_nuevo',
        'motivo',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'secuencial_anterior' => 'integer',
        'secuencial_nuevo' => 'integer',
    ];

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(SriSequence::class, 'sri_sequence_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
