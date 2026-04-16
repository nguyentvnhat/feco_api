<?php

namespace Modules\Order\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Order\Enums\OrderStatus;

class UpdateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'order_date' => ['required', 'date'],
            'seller_user_id' => ['required', 'integer', 'exists:users,id'],
            'agent_profile_id' => ['nullable', 'integer', 'exists:agent_profiles,id'],
            'order_channel' => ['required', 'in:agent_order,direct_sale,internal_sale'],
            'order_status' => ['required', Rule::in(OrderStatus::values())],
            'customer_name' => ['required', 'string', 'max:150'],
            'customer_phone' => ['required', 'string', 'max:20'],
            'customer_address' => ['nullable', 'string', 'max:512'],
            'customer_province_code' => ['required', 'string', 'max:32', 'exists:provinces,code'],
            'customer_district_code' => ['nullable', 'string', 'max:32'],
            'customer_district_name' => ['nullable', 'string', 'max:255'],
            'customer_ward_code' => [
                'required',
                'string',
                'max:32',
                Rule::exists('wards', 'code')->where(function ($q) {
                    $q->where('province_code', (string) $this->input('customer_province_code'));
                }),
            ],
        ];
    }
}
