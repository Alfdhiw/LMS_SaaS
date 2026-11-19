<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Identity\Services\ExistingAccountInvitationService;
use App\Support\Auth\LoginRateLimiter;
use App\Support\Tenancy\TenantContextStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ExistingAccountInvitationController extends Controller
{
    public function __construct(private readonly TenantContextStore $contexts, private readonly ExistingAccountInvitationService $invitations, private readonly LoginRateLimiter $limiter) {}

    public function issue(Request $request): Response
    {
        abort_if(app()->environment('production') && ! $request->isSecure(), 403);
        $data = $request->validate([
            'username' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'role' => ['required', 'in:teacher,student'],
        ]);
        $actor = $request->attributes->get('tenant_user');
        $membership = $request->attributes->get('tenant_membership');
        abort_unless($actor instanceof User && $membership !== null, 403);
        $issued = $this->invitations->issue($request, $this->contexts->require(), $actor, (int) $membership->id, $data['username'], $data['role']);

        if ($request->expectsJson()) {
            return response()->json(['data' => $issued], 201)->header('Cache-Control', 'no-store');
        }

        return response()->view('auth.offline-invitation', ['issued' => $issued, 'kind' => 'existing', 'host' => $request->getSchemeAndHttpHost()], 201)
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function accept(Request $request): Response
    {
        abort_if(app()->environment('production') && ! $request->isSecure(), 403);
        $validator = Validator::make($request->all(), [
            'invitation_id' => ['required', 'ulid'], 'token' => ['required', 'string', 'size:64'],
            'email' => ['required', 'email', 'max:254'], 'password' => ['required', 'string', 'max:1024'],
        ]);
        if ($validator->fails()) {
            return response()->view('auth.invitation-accept', ['error' => 'Data undangan tidak valid.'], 422)->header('Cache-Control', 'no-store');
        }
        $data = $validator->validated();
        $context = $this->contexts->require();
        $identifier = 'join|'.mb_strtolower(trim($data['email']));
        $globalKey = 'identity:join:'.hash_hmac('sha256', $identifier, (string) config('app.key'));
        abort_if($this->limiter->isLimited($request, $context, $identifier) || RateLimiter::tooManyAttempts($globalKey, 5), 429);
        try {
            $this->invitations->accept($request, $context, $data['invitation_id'], $data['token'], $data['email'], $data['password']);
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() === 404) {
                $this->limiter->recordFailure($request, $context, $identifier);
                RateLimiter::hit($globalKey, 60);
            }
            throw $exception;
        }
        $this->limiter->clear($request, $context, $identifier);
        RateLimiter::clear($globalKey);

        return $request->expectsJson() ? response()->json(['status' => 'joined'])->header('Cache-Control', 'no-store')
            : redirect('/login')->with('status', 'Keanggotaan berhasil dibuat. Silakan masuk dengan username sekolah.');
    }
}
