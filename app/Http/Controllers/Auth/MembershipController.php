<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Identity\Services\MembershipManagementService;
use App\Support\Tenancy\TenantContextStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MembershipController extends Controller
{
    public function __construct(private readonly TenantContextStore $contexts, private readonly MembershipManagementService $members) {}

    public function status(Request $request, string $userUlid): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:active,suspended']]);
        $actor = $request->attributes->get('tenant_user');
        $membership = $request->attributes->get('tenant_membership');
        abort_unless($actor instanceof User && $membership !== null, 403);
        $this->members->setStatus($request, $this->contexts->require(), $actor, (int) $membership->id, $userUlid, $data['status']);

        return response()->json(['status' => 'updated']);
    }

    public function roles(Request $request, string $userUlid): JsonResponse
    {
        $data = $request->validate(['roles' => ['required', 'array', 'min:1', 'max:4'], 'roles.*' => ['required', 'string', 'distinct']]);
        $actor = $request->attributes->get('tenant_user');
        $membership = $request->attributes->get('tenant_membership');
        abort_unless($actor instanceof User && $membership !== null, 403);
        $this->members->setRoles($request, $this->contexts->require(), $actor, (int) $membership->id, $userUlid, $data['roles']);

        return response()->json(['status' => 'updated']);
    }
}
