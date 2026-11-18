<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

final class ProvisionTenantRequest extends FormRequest
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
            'school_name' => ['required', 'string', 'max:200'],
            'slug' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/'],
            'timezone' => ['required', 'string', 'max:64'],
            'admin_name' => ['required', 'string', 'max:150'],
            'admin_email' => ['required', 'email', 'max:254'],
            'admin_username' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/'],
        ];
    }
}
