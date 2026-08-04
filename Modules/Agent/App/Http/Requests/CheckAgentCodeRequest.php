<?php

namespace Modules\Agent\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckAgentCodeRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:32'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'Mã đại lý là bắt buộc.',
            'code.string' => 'Mã đại lý không hợp lệ.',
            'code.max' => 'Mã đại lý không được vượt quá 32 ký tự.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge([
                'code' => trim((string) $this->input('code')),
            ]);
        }
    }
}
