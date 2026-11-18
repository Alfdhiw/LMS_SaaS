<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\TenantActivationRequest;
use App\Modules\Identity\Services\BootstrapAdminActivationService;
use App\Support\Tenancy\TenantContextStore;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class TenantActivationController extends Controller
{
    public function __construct(
        private readonly TenantContextStore $contextStore,
        private readonly BootstrapAdminActivationService $activation,
    ) {}

    public function show(Request $request, string $invitationPublicUlid, string $token): Response
    {
        $context = $this->contextStore->require();
        $invitation = $this->activation->inspect($context, $invitationPublicUlid, $token);

        if ($invitation === null) {
            abort(404);
        }

        return response()->view('auth.activation', [
            'tenantName' => $context->tenantSlug,
            'displayName' => $invitation['display_name'],
            'action' => $request->url(),
        ]);
    }

    public function activate(
        TenantActivationRequest $request,
        string $invitationPublicUlid,
        string $token,
    ): Response {
        $context = $this->contextStore->require();
        $activated = $this->activation->activate(
            request: $request,
            context: $context,
            invitationPublicUlid: $invitationPublicUlid,
            token: $token,
            password: $request->validated('password'),
        );

        if (! $activated) {
            abort(404);
        }

        return redirect('/login')->with('status', 'Akun admin aktif. Silakan login.');
    }
}
