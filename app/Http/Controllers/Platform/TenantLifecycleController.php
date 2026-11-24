<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\TransitionTenantRequest;
use App\Models\User;
use App\Modules\Platform\Services\TenantLifecycleService;
use App\Support\Exceptions\OperationException;
use Symfony\Component\HttpFoundation\Response;

final class TenantLifecycleController extends Controller
{
    public function __construct(private readonly TenantLifecycleService $lifecycle) {}

    public function activate(TransitionTenantRequest $request, string $publicUlid): Response
    {
        return $this->transition($request, $publicUlid, 'activate');
    }

    public function suspend(TransitionTenantRequest $request, string $publicUlid): Response
    {
        return $this->transition($request, $publicUlid, 'suspend');
    }

    public function restore(TransitionTenantRequest $request, string $publicUlid): Response
    {
        return $this->transition($request, $publicUlid, 'restore');
    }

    public function archive(TransitionTenantRequest $request, string $publicUlid): Response
    {
        return $this->transition($request, $publicUlid, 'archive');
    }

    private function transition(TransitionTenantRequest $request, string $publicUlid, string $action): Response
    {
        $actor = $request->attributes->get('central_user');

        if (! $actor instanceof User) {
            abort(403);
        }

        try {
            $result = $this->lifecycle->transition(
                request: $request,
                actor: $actor,
                tenantPublicUlid: $publicUlid,
                action: $action,
                expectedStatus: $request->validated('expected_status'),
                reason: $request->validated('reason'),
            );
        } catch (OperationException $exception) {
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

        if (! $request->expectsJson()) {
            return redirect()
                ->route('central.tenants.show', $publicUlid)
                ->with('status', 'Status tenant berhasil diubah.');
        }

        return response()->json(['data' => $result]);
    }
}
