<?php

namespace App\Models;

use App\Models\Contracts\JsonResourceful;
use App\Traits\HasJsonResourcefulData;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Models\MailTemplate
 *
 * @property int $id
 * @property string $template_name
 * @property string $content
 * @property string $type
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder|MailTemplate newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|MailTemplate newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|MailTemplate query()
 * @method static \Illuminate\Database\Eloquent\Builder|MailTemplate whereContent($value)
 * @method static \Illuminate\Database\Eloquent\Builder|MailTemplate whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|MailTemplate whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|MailTemplate whereTemplateName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|MailTemplate whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|MailTemplate whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|MailTemplate whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class MailTemplate extends BaseModel implements JsonResourceful
{
    use HasFactory, HasJsonResourcefulData;

    public const JSON_API_TYPE = 'mail_templates';

    protected $table = 'mail_templates';

    protected $fillable = ['store_id', 'template_name', 'subject', 'content', 'type', 'status'];

    const MAIL_TYPE_SALE = 1;

    const MAIL_TYPE_SALE_RETURN = 2;

    const MAIL_TYPE_ELECTRONIC_INVOICE = 3;

    const ACTIVE = 1;

    const INACTIVE = 0;

    public static $rules = [
        'content' => 'required',
    ];

    public function prepareLinks(): array
    {
        return [

        ];
    }

    public function prepareAttributes(): array
    {
        $fields = [
            'template_name' => $this->template_name,
            'subject' => $this->subject,
            'content' => $this->content,
            'type' => $this->type,
            'status' => $this->status,
        ];

        return $fields;
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public static function effectiveForStore(?int $storeId, $type): ?self
    {
        return static::where('type', $type)
            ->where(function ($query) use ($storeId) {
                $query->whereNull('store_id');
                if ($storeId) {
                    $query->orWhere('store_id', $storeId);
                }
            })
            ->orderByDesc('store_id')
            ->first();
    }
}
