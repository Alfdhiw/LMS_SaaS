<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Services\SelfEmailRecoveryService;
use App\Support\Tenancy\TenantContextStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

final class SelfEmailRecoveryController extends Controller
{
    public function __construct(private readonly TenantContextStore $contexts, private readonly SelfEmailRecoveryService $recovery) {}

    public function requestForm(): Response
    {
        return response()->view('auth.forgot-password')->header('Cache-Control', 'no-store');
    }

    public function issue(Request $request): Response
    {
        abort_if(app()->environment('production') && ! $request->isSecure(), 403);
        $data = $request->validate(['email' => ['required', 'email', 'max:254']]);
        $this->recovery->issue($request, $this->contexts->require(), $data['email']);
        $message = 'Jika email memenuhi syarat, petunjuk pemulihan akan dikirim.';

        return $request->expectsJson() ? response()->json(['message' => $message])->header('Cache-Control', 'no-store')
            : response()->view('auth.forgot-password', ['status' => $message])->header('Cache-Control', 'no-store');
    }

    public function resetForm(): Response
    {
        return response()->view('auth.reset-password')->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function consume(Request $request): Response
    {
        abort_if(app()->environment('production') && ! $request->isSecure(), 403);
        $validator = Validator::make($request->all(), [
            'recovery_id' => ['required', 'ulid'], 'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'min:12', 'max:1024', 'confirmed'],
        ]);
        if ($validator->fails()) {
            return $request->expectsJson() ? response()->json(['message' => 'Data pemulihan tidak valid.'], 422)->header('Cache-Control', 'no-store')
                : response()->view('auth.reset-password', ['error' => 'Data tidak valid. Buka kembali tautan email dan pastikan password cocok.'], 422)->header('Cache-Control', 'no-store');
        }
        $data = $validator->validated();
        $this->recovery->consume($request, $this->contexts->require(), $data['recovery_id'], $data['token'], $data['password']);

        return $request->expectsJson() ? response()->json(['status' => 'password_updated'])->header('Cache-Control', 'no-store')
            : redirect('/login')->with('status', 'Password berhasil diperbarui. Silakan masuk kembali.');
    }
}
