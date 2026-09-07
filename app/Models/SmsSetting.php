<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Models\SmsSetting
 *
 * @property int $id
 * @property string $key
 * @property string $value
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder|SmsSetting newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|SmsSetting newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|SmsSetting query()
 * @method static \Illuminate\Database\Eloquent\Builder|SmsSetting whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SmsSetting whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SmsSetting whereKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SmsSetting whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SmsSetting whereValue($value)
 * @mixin \Eloquent
 */
class SmsSetting extends Model
{
    use HasFactory;

    const PATH = 'sms_settings';

    protected $table = 'sms_settings';

    // status

    public const ACTIVE = 1;

    public const INACTIVE = 2;

    /**
     * @var string[]
     */
    protected $fillable = [
        'store_id',
        'key',
        'value',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public static function effectiveValue(?int $storeId, string $key): ?string
    {
        return static::where('key', $key)
            ->where(function ($query) use ($storeId) {
                $query->whereNull('store_id');
                if ($storeId) {
                    $query->orWhere('store_id', $storeId);
                }
            })
            ->orderByDesc('store_id')
            ->value('value');
    }
}
