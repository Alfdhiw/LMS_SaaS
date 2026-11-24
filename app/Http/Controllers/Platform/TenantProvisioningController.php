<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\ProvisionTenantRequest;
use App\Http\Requests\Platform\ResendTenantInvitationRequest;
use App\Models\User;
use App\Modules\Platform\Data\ProvisionTenantData;
use App\Modules\Platform\Services\TenantDirectoryService;
use App\Modules\Platform\Services\TenantInvitationDeliveryService;
use App\Modules\Platform\Services\TenantProvisioningService;
use App\Support\Exceptions\OperationException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class TenantProvisioningController extends Controller
{
    public function __construct(
        private readonly TenantDirectoryService $directory,
        private readonly TenantInvitationDeliveryService $delivery,
        private readonly TenantProvisioningService $provisioning,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:pending,active,suspended,archived'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);
        $tenants = $this->directory->all($filters);

        if (! $request->expectsJson()) {
            return response()->view('platform.tenants.index', [
                'tenants' => $tenants,
                'filters' => $filters,
                'idempotencyKey' => (string) Str::uuid(),
            ]);
        }

        return response()->json(['data' => $tenants->items(), 'meta' => [
            'current_page' => $tenants->currentPage(), 'last_page' => $tenants->lastPage(), 'total' => $tenants->total(),
        ]]);
    }

    public function show(Request $request, string $publicUlid): Response
    {
        $tenant = $this->directory->find($publicUlid);

        if ($tenant === null) {
            abort(404);
        }

        if (! $request->expectsJson()) {
            return response()->view('platform.tenants.show', ['tenant' => $tenant]);
        }

        return response()->json(['data' => $tenant]);
    }

    public function store(ProvisionTenantRequest $request): Response
    {
        $actor = $request->attributes->get('central_user');

        if (! $actor instanceof User) {
            abort(403);
        }

        if (! $request->headers->has('Idempotency-Key') && $request->filled('idempotency_key')) {
            $request->headers->set('Idempotency-Key', (string) $request->input('idempotency_key'));
        }

        try {
            $result = $this->provisioning->provision(
                request: $request,
                actor: $actor,
                data: ProvisionTenantData::fromArray($request->validated()),
            );
        } catch (OperationException $exception) {
            return $this->errorResponse($request, $exception);
        }

        $payload = $result->toArray(app()->environment(['local', 'testing']));
        $payload['invitation_delivery'] = $this->delivery->sendProvisioned($request, $actor, $result);

        if (! $request->expectsJson()) {
            return redirect()
                ->route('central.tenants.show', $result->tenantPublicUlid)
                ->with('status', 'Tenant berhasil diprovision dalam status pending.')
                ->with('provisioning', $payload);
        }

        return response()->json(['data' => $payload], $result->replayed ? 200 : 201);
    }

    public function resend(ResendTenantInvitationRequest $request, string $publicUlid): Response
    {
        $actor = $request->attributes->get('central_user');

        if (! $actor instanceof User) {
            abort(403);
        }

        try {
            $result = $this->delivery->resend(
                request: $request,
                actor: $actor,
                tenantPublicUlid: $publicUlid,
                invitationPublicUlid: (string) $request->validated('invitation_public_ulid'),
            );
        } catch (OperationException $exception) {
            return $this->errorResponse($request, $exception);
        }

        $payload = $result->toArray(app()->environment(['local', 'testing']));
        $payload['invitation_delivery'] = $this->delivery->sendProvisioned($request, $actor, $result);

        if (! $request->expectsJson()) {
            return redirect()
                ->route('central.tenants.show', $result->tenantPublicUlid)
                ->with('status', 'Invitation admin dikirim ulang.')
                ->with('provisioning', $payload);
        }

        return response()->json(['data' => $payload]);
    }

    private function errorResponse(Request $request, OperationException $exception): Response
    {
        if (! $request->expectsJson()) {
            return back()
                ->withErrors([$exception->failureCode => $exception->getMessage()])
                ->withInput();
        }

        return response()->json([
            'message' => $exception->getMessage(),
            'failure_code' => $exception->failureCode,
        ], $exception->statusCode);
    }
}
