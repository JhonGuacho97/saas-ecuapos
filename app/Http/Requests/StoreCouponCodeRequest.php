<?php

namespace App\Http\Requests;

use App\Models\CouponCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCouponCodeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = CouponCode::$rules;
        $rules['code'] = [
            'required',
            Rule::unique('coupon_codes', 'code')->where(fn ($query) => $query->where('store_id', currentStoreId())),
        ];

        return $rules;
    }
}
