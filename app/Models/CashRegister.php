<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CashRegister extends BaseModel
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'warehouse_id', 'name', 'code', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];

    public function store(): BelongsTo { return $this->belongsTo(Store::class); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function sessions(): HasMany { return $this->hasMany(POSRegister::class); }
    public function activeSession(): HasOne { return $this->hasOne(POSRegister::class)->whereNull('closed_at')->latestOfMany(); }
    public function movements(): HasMany { return $this->hasMany(CashMovement::class); }
}
