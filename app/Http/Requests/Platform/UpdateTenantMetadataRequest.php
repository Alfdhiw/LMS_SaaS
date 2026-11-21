<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateTenantMetadataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'timezone' => ['required', 'string', 'max:64', 'timezone'],
            'slug' => ['prohibited'], 'hostname' => ['prohibited'],
            'tenant_id' => ['prohibited'], 'status' => ['prohibited'],
        ];
    }
}
