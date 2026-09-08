<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStoreThroughParent;
use App\Models\Contracts\JsonResourceful;
use App\Traits\HasJsonResourcefulData;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder;

/**
 * App\Models\SalesPayment
 *
 * @property int $id
 * @property int $sale_id
 * @property int $reference
 * @property string $payment_date
 * @property int|null $payment_type
 * @property float|null $amount
 * @property float|null $received_amount
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder|SalesPayment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|SalesPayment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|SalesPayment query()
 * @method static \Illuminate\Database\Eloquent\Builder|SalesPayment whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SalesPayment whereReceivedAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SalesPayment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SalesPayment whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SalesPayment wherePaymentDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SalesPayment wherePaymentType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SalesPayment whereSaleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SalesPayment whereReference($value)
 * @method static \Illuminate\Database\Eloquent\Builder|SalesPayment whereUpdatedAt($value)
 * @property-read \App\Models\Sale $sale
 * @method static \Illuminate\Database\Eloquent\Builder|SalesPayment user($userId)
 * @mixin \Eloquent
 */
class SalesPayment extends BaseModel implements JsonResourceful
{
    use BelongsToStoreThroughParent, HasFactory, HasJsonResourcefulData;

    protected $table = 'sales_payments';

    protected function storeParentRelationName(): string
    {
        return 'sale';
    }

    public const JSON_API_TYPE = 'sales_payments';

    public const CASH = 1;

    public const CHEQUE = 2;

    public const BANK_TRANSFER = 3;

    public const OTHER = 4;

    /**
     * @var string[]
     */
    protected $fillable = [
        'sale_id',
        'pos_register_id',
        'reference',
        'payment_date',
        'payment_type',
        'amount',
        'received_amount',
    ];

    /**
     * Hace que getPaymentMethodAttribute() aparezca en la respuesta JSON
     * cuando el modelo se serializa "en crudo" (ej. dentro de sale.payments,
     * igual que ya hace sale_items con sus modelos).
     *
     * @var string[]
     */
    protected $appends = ['payment_method'];

    /**
     * @var string[]
     */
    public static $rules = [
        'payment_date' => 'date',
        'payment_type' => 'required|integer|in:1,2,3,4',
        'amount' => 'required|numeric|min:0.01',
    ];

    public function prepareLinks(): array
    {
        return [

        ];
    }

    public function prepareAttributes(): array
    {
        $fields = [
            'sale_id' => $this->sale_id,
            'reference' => $this->reference,
            'payment_date' => $this->payment_date,
            'payment_type' => $this->payment_type,
            'amount' => $this->amount,
            'received_amount' => $this->received_amount,
        ];

        return $fields;
    }

    /**
     * Detalle legible del método de pago, sintetizado a partir de las
     * constantes (CASH/CHEQUE/BANK_TRANSFER/OTHER) -- no depende de una
     * tabla nueva de payment_methods, solo mapea el número guardado.
     */
    public function getPaymentMethodAttribute(): array
    {
        $names = [
            self::CASH => 'Cash',
            self::CHEQUE => 'Cheque',
            self::BANK_TRANSFER => 'Bank Transfer',
            self::OTHER => 'Other',
        ];

        return [
            'id' => $this->payment_type,
            'name' => $names[$this->payment_type] ?? 'Other',
            'status' => 1,
            'type' => 0,
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id', 'id');
    }

    public function posRegister(): BelongsTo
    {
        return $this->belongsTo(POSRegister::class, 'pos_register_id');
    }

    public function scopeUser(Builder $builder, $userId)
    {
        return $builder->whereHas('sale', function ($query) use ($userId) {
            $query->where('user_id', $userId);
        });
    }
}
