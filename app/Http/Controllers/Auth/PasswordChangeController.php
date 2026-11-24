<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Modules\Identity\Actions\ChangeOwnPassword;
use App\Modules\Identity\Services\AuthSessionManager;
use App\Modules\Identity\Support\PasswordPolicy;
use App\Support\Tenancy\TenantContextStore;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PasswordChangeController extends Controller
{
    public function edit(Request $request): Response
    {
        return response()->view('auth.change-password', [
            'central' => $request->attributes->has('central_user'), 'passwordByteLimit' => PasswordPolicy::byteLimit(),
        ])
            ->header('Cache-Control', 'no-store');
    }

    public function update(ChangePasswordRequest $request, ChangeOwnPassword $change, AuthSessionManager $sessions, TenantContextStore $contexts): Response
    {
        $central = $request->attributes->has('central_user');
        $actor = $request->attributes->get($central ? 'central_user' : 'tenant_user');
        $values = $request->validated();
        $change->handle($request, $actor, $central ? null : $contexts->require(), $values['current_password'], $values['password']);
        $sessions->logout($request);

        return redirect()->to('/login')->with('status', 'Password berhasil diubah. Semua sesi dicabut; silakan login kembali.')
            ->header('Cache-Control', 'no-store');
    }
}
