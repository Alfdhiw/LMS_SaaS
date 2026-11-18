<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

final class ResendTenantInvitationRequest extends FormRequest
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
            'invitation_public_ulid' => ['required', 'string', 'size:26', 'regex:/^[0-9A-HJKMNP-TV-Z]+$/'],
        ];
    }
}
