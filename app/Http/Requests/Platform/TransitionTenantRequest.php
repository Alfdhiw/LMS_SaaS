<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

final class TransitionTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'expected_status' => ['required', 'string', 'in:pending,active,suspended,archived'],
            'reason' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9_:-]+$/'],
        ];
    }
}
