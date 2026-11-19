<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Identity\Services\SchoolAssistedRecoveryService;
use App\Support\Tenancy\TenantContextStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class SchoolAssistedRecoveryController extends Controller
{
    public function __construct(private readonly TenantContextStore $contexts, private readonly SchoolAssistedRecoveryService $recovery) {}

    public function studentForm(): Response
    {
        return response()->view('auth.school-recovery-student')->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function adminForm(): Response
    {
        return response()->view('auth.school-recovery-admin')->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function requestRecovery(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:64'],
            'commitment' => ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/'],
        ]);
        $id = $this->recovery->request($request, $this->contexts->require(), $data['username'], $data['commitment']);

        return response()->json(['recovery_id' => $id, 'message' => 'Tunjukkan ID permintaan kepada petugas sekolah.'])->header('Cache-Control', 'no-store');
    }

    public function preview(string $recoveryId): JsonResponse
    {
        abort_unless(Str::isUlid($recoveryId), 404);

        return response()->json(['data' => $this->recovery->preview($this->contexts->require(), $recoveryId)])->header('Cache-Control', 'no-store');
    }

    public function approve(Request $request, string $recoveryId): JsonResponse
    {
        abort_unless(Str::isUlid($recoveryId), 404);
        $request->validate(['identity_verified_offline' => ['required', 'accepted']]);
        $actor = $request->attributes->get('tenant_user');
        $membership = $request->attributes->get('tenant_membership');
        abort_unless($actor instanceof User && $membership !== null, 403);
        $this->recovery->approve($request, $this->contexts->require(), $actor, (int) $membership->id, $recoveryId);

        return response()->json(['status' => 'approved'])->header('Cache-Control', 'no-store');
    }

    public function consume(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'recovery_id' => ['required', 'ulid'],
            'token' => ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/'],
            'password' => ['required', 'string', 'min:12', 'max:1024', 'confirmed'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Data pemulihan tidak valid.'], 422)->header('Cache-Control', 'no-store');
        }
        $data = $validator->validated();
        $this->recovery->consume($request, $this->contexts->require(), $data['recovery_id'], $data['token'], $data['password']);

        return response()->json(['status' => 'password_updated'])->header('Cache-Control', 'no-store');
    }
}
