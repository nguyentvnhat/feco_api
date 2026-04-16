<?php

namespace Modules\Agent\App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListChildAgentsRequest extends FormRequest
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
            'agent_type_id' => ['nullable', 'integer', 'exists:agent_types,id'],
        ];
    }
}
