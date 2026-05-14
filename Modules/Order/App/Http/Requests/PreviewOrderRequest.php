<?php

namespace Modules\Order\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewOrderRequest extends FormRequest
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
            'order_channel' => ['required', 'string', Rule::in(['agent_order'])],
            'products' => ['required', 'array', 'min:1'],
            'products.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'products.*.quantity' => ['required', 'numeric', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'order_channel.required' => 'Kênh đơn hàng là bắt buộc.',
            'order_channel.in' => 'Kênh đơn hàng không hợp lệ.',
            'products.required' => 'Danh sách sản phẩm là bắt buộc.',
            'products.min' => 'Đơn hàng phải có ít nhất một sản phẩm.',
            'products.*.product_id.required' => 'Mã sản phẩm là bắt buộc.',
            'products.*.product_id.exists' => 'Sản phẩm không tồn tại.',
            'products.*.quantity.required' => 'Số lượng là bắt buộc.',
            'products.*.quantity.min' => 'Số lượng tối thiểu là 1.',
        ];
    }
}
