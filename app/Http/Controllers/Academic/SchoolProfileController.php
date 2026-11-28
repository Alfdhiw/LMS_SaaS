<?php

namespace App\Http\Controllers\Academic;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Services\PermissionChecker;
use App\Modules\Identity\Services\SchoolProfileWorkflow;
use App\Modules\Tenancy\Data\SchoolProfileUpdate;
use App\Support\Tenancy\TenantContextStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class SchoolProfileController extends Controller
{
    public function __construct(private readonly SchoolProfileWorkflow $profiles) {}

    public function edit(Request $request): Response
    {
        $user = $request->attributes->get('tenant_user');
        $member = $request->attributes->get('tenant_membership');

        return response()->view('academic.school-profile', [
            'profile' => $this->profiles->read($request),
            'canUpdate' => $user !== null && $member !== null
                && app(PermissionChecker::class)->allows($user, app(TenantContextStore::class)->require(), (int) $member->id, 'school.update'),
        ])->header('Cache-Control', 'no-store');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'timezone' => ['required', 'string', Rule::in(\DateTimeZone::listIdentifiers())],
            'accent_color' => ['nullable', 'regex:/\A#[0-9a-fA-F]{6}\z/'],
            'tenant_id' => ['prohibited'], 'slug' => ['prohibited'], 'status' => ['prohibited'],
            'hostname' => ['prohibited'], 'onboarding_json' => ['prohibited'],
        ]);
        $this->profiles->update($request, new SchoolProfileUpdate($data['name'], $data['timezone'], $data['accent_color'] ?? null));

        return redirect()->route('tenant.school.edit')->with('status', 'Profil sekolah berhasil diperbarui.');
    }
}
