<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Identity\Services\MemberInvitationService;
use App\Support\Tenancy\TenantContextStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

final class MemberInvitationController extends Controller
{
    public function __construct(private readonly TenantContextStore $contexts, private readonly MemberInvitationService $invitations) {}

    public function invite(Request $request): Response
    {
        abort_if(app()->environment('production') && ! $request->isSecure(), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'username' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'role' => ['required', 'in:student,teacher'],
        ]);
        $actor = $request->attributes->get('tenant_user');
        $membership = $request->attributes->get('tenant_membership');
        abort_unless($actor instanceof User && $membership !== null, 403);
        $result = $this->invitations->invite($request, $this->contexts->require(), $actor, (int) $membership->id, $data['name'], $data['username'], $data['role']);

        return $request->input('_interface') !== 'web' ? response()->json(['data' => $result], 201)->header('Cache-Control', 'no-store')
            : response()->view('auth.offline-invitation', ['issued' => $result, 'kind' => 'new', 'host' => $request->getSchemeAndHttpHost()], 201)
                ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function activate(Request $request): Response
    {
        abort_if(app()->environment('production') && ! $request->isSecure(), 403);
        $validator = Validator::make($request->all(), [
            'invitation_id' => ['required', 'ulid'], 'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);
        if ($validator->fails()) {
            return $request->input('_interface') === 'web'
                ? response()->view('auth.invitation-accept', ['error' => 'Data aktivasi tidak valid.'], 422)->header('Cache-Control', 'no-store')
                : response()->json(['message' => 'Data aktivasi tidak valid.'], 422)->header('Cache-Control', 'no-store');
        }
        $data = $validator->validated();
        $this->invitations->activate($request, $this->contexts->require(), $data['invitation_id'], $data['token'], $data['password']);

        return $request->input('_interface') !== 'web' ? response()->json(['status' => 'activated'])->header('Cache-Control', 'no-store')
            : redirect('/login')->with('status', 'Akun berhasil diaktifkan. Silakan masuk.');
    }
}
