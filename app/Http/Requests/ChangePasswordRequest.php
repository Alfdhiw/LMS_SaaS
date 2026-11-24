<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Modules\Identity\Support\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

final class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('tenant_user') instanceof User || $this->attributes->get('central_user') instanceof User;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:1024'],
            'password' => PasswordPolicy::rules(),
            'user_id' => ['prohibited'], 'target_user_id' => ['prohibited'], 'user_ulid' => ['prohibited'],
            'tenant_id' => ['prohibited'], 'membership_id' => ['prohibited'], 'auth_version' => ['prohibited'],
        ];
    }
}
