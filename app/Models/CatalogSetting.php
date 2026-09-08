<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogSetting extends BaseModel
{
    use BelongsToStore;

    protected $fillable = [
        'store_id', 'warehouse_id', 'is_enabled', 'whatsapp_number',
        'headline', 'description', 'show_stock', 'allow_pickup',
        'allow_delivery', 'delivery_fee', 'minimum_order',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'show_stock' => 'boolean',
        'allow_pickup' => 'boolean',
        'allow_delivery' => 'boolean',
        'delivery_fee' => 'float',
        'minimum_order' => 'float',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
