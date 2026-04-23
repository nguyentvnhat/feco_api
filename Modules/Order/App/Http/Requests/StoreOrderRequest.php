<?php

namespace Modules\Order\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Order\Enums\OrderStatus;

class StoreOrderRequest extends FormRequest
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

    public function messages(): array
{
    return [
        'order_date.required' => 'Ngày đặt hàng là bắt buộc.',
        'order_date.date' => 'Ngày đặt hàng không đúng định dạng.',

        'seller_user_id.required' => 'Nhân viên bán hàng là bắt buộc.',
        'seller_user_id.integer' => 'Nhân viên bán hàng không hợp lệ.',
        'seller_user_id.exists' => 'Nhân viên bán hàng không tồn tại.',

        'agent_profile_id.integer' => 'Agent không hợp lệ.',
        'agent_profile_id.exists' => 'Agent không tồn tại.',

        'order_channel.required' => 'Kênh đơn hàng là bắt buộc.',
        'order_channel.in' => 'Kênh đơn hàng không hợp lệ.',

        'order_status.required' => 'Trạng thái đơn hàng là bắt buộc.',
        'order_status.in' => 'Trạng thái đơn hàng không hợp lệ.',

        'customer_name.required' => 'Tên khách hàng là bắt buộc.',
        'customer_name.string' => 'Tên khách hàng không hợp lệ.',
        'customer_name.max' => 'Tên khách hàng không được vượt quá 150 ký tự.',

        'customer_phone.required' => 'Số điện thoại khách hàng là bắt buộc.',
        'customer_phone.string' => 'Số điện thoại không hợp lệ.',
        'customer_phone.max' => 'Số điện thoại không được vượt quá 20 ký tự.',

        'customer_address.string' => 'Địa chỉ không hợp lệ.',
        'customer_address.max' => 'Địa chỉ không được vượt quá 512 ký tự.',

        'customer_province_code.required' => 'Tỉnh/Thành phố là bắt buộc.',
        'customer_province_code.string' => 'Tỉnh/Thành phố không hợp lệ.',
        'customer_province_code.max' => 'Mã tỉnh không được vượt quá 32 ký tự.',
        'customer_province_code.exists' => 'Tỉnh/Thành phố không tồn tại.',

        'customer_district_code.string' => 'Quận/Huyện không hợp lệ.',
        'customer_district_code.max' => 'Mã quận/huyện không được vượt quá 32 ký tự.',

        'customer_district_name.string' => 'Tên quận/huyện không hợp lệ.',
        'customer_district_name.max' => 'Tên quận/huyện không được vượt quá 255 ký tự.',

        'customer_ward_code.required' => 'Phường/Xã là bắt buộc.',
        'customer_ward_code.string' => 'Phường/Xã không hợp lệ.',
        'customer_ward_code.max' => 'Mã phường/xã không được vượt quá 32 ký tự.',
        'customer_ward_code.exists' => 'Phường/Xã không tồn tại trong tỉnh đã chọn.',
    ];
}
}
