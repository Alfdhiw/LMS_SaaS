<?php

namespace App\Http\Controllers\Academic;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Actions\AcademicAdministration;
use App\Modules\Academic\Queries\AcademicDirectory;
use App\Modules\Identity\Contracts\AcademicIdentity;
use App\Modules\Identity\Contracts\MembershipEligibilityReader;
use App\Modules\Identity\Data\MembershipSelection;
use App\Support\Tenancy\TenantContextStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AcademicController extends Controller
{
    public function __construct(
        private readonly AcademicAdministration $actions,
        private readonly AcademicDirectory $directory,
        private readonly AcademicIdentity $identity,
        private readonly MembershipEligibilityReader $eligibility,
        private readonly TenantContextStore $contexts,
    ) {}

    public function periods(): Response
    {
        $context = $this->contexts->require();
        $years = $this->directory->years($context);

        return response()->view('academic.periods', ['years' => $years, 'semesters' => $this->directory->semesters($context, $years->getCollection()->pluck('id')->all()),
            'schoolSlug' => $context->tenantSlug, 'activeYear' => $this->directory->activeYear($context)])->header('Cache-Control', 'no-store');
    }

    public function createYear(Request $request): RedirectResponse
    {
        $data = $request->validate($this->periodRules(['name' => ['required', 'string', 'max:40']]));
        $this->actions->createYear($request, $this->contexts->require(), $data);

        return back()->with('status', 'Tahun ajaran dibuat sebagai draft.');
    }

    public function createSemester(Request $request, string $yearUlid): RedirectResponse
    {
        $this->directory->requireResource($this->contexts->require(), 'year', $yearUlid);
        $data = $request->validate($this->periodRules(['code' => ['required', 'in:odd,even']]));
        $this->actions->createSemester($request, $this->contexts->require(), $yearUlid, $data);

        return back()->with('status', 'Semester dibuat sebagai draft.');
    }

    public function updateYear(Request $request, string $yearUlid): RedirectResponse
    {
        $this->directory->requireResource($this->contexts->require(), 'year', $yearUlid);
        $data = $request->validate($this->periodRules(['name' => ['required', 'string', 'max:40']]));
        $this->actions->updateYear($request, $this->contexts->require(), $yearUlid, $data);

        return back()->with('status', 'Tahun ajaran diperbarui.');
    }

    public function updateSemester(Request $request, string $semesterUlid): RedirectResponse
    {
        $this->directory->requireResource($this->contexts->require(), 'semester', $semesterUlid);
        $data = $request->validate($this->periodRules([]));
        $this->actions->updateSemester($request, $this->contexts->require(), $semesterUlid, $data);

        return back()->with('status', 'Semester diperbarui.');
    }

    public function transitionYear(Request $request, string $yearUlid): RedirectResponse
    {
        $this->directory->requireResource($this->contexts->require(), 'year', $yearUlid);
        $data = $request->validate(['status' => ['required', 'in:active,closed'], 'tenant_id' => ['prohibited']]);
        $this->actions->transitionYear($request, $this->contexts->require(), $yearUlid, $data['status']);

        return back()->with('status', 'Status tahun ajaran diperbarui.');
    }

    public function transitionSemester(Request $request, string $semesterUlid): RedirectResponse
    {
        $this->directory->requireResource($this->contexts->require(), 'semester', $semesterUlid);
        $data = $request->validate(['status' => ['required', 'in:active,closed'], 'tenant_id' => ['prohibited']]);
        $this->actions->transitionSemester($request, $this->contexts->require(), $semesterUlid, $data['status']);

        return back()->with('status', 'Status semester diperbarui.');
    }

    public function subjects(): Response
    {
        $context = $this->contexts->require();

        return response()->view('academic.subjects', ['subjects' => $this->directory->subjects($context),
            'schoolSlug' => $context->tenantSlug, 'activeYear' => $this->directory->activeYear($context)])->header('Cache-Control', 'no-store');
    }

    public function createSubject(Request $request): RedirectResponse
    {
        $data = $request->validate($this->subjectRules());
        $this->actions->createSubject($request, $this->contexts->require(), $data);

        return back()->with('status', 'Mata pelajaran berhasil dibuat.');
    }

    public function updateSubject(Request $request, string $subjectUlid): RedirectResponse
    {
        $this->directory->requireResource($this->contexts->require(), 'subject', $subjectUlid);
        $data = $request->validate($this->subjectRules() + ['is_active' => ['required', 'boolean']]);
        $data['is_active'] = (bool) $data['is_active'];
        $this->actions->updateSubject($request, $this->contexts->require(), $subjectUlid, $data);

        return back()->with('status', 'Mata pelajaran diperbarui.');
    }

    public function profiles(Request $request, string $type): Response
    {
        abort_unless(in_array($type, ['student', 'teacher'], true), 404);
        $context = $this->contexts->require();
        $this->identity->authorize($request, $context, 'members.view');
        $profiles = $this->directory->profiles($context, $type);
        $ids = $profiles->getCollection()->pluck('membership_id')->map(fn ($id) => (int) $id)->all();

        return response()->view('academic.profiles', ['type' => $type, 'profiles' => $profiles,
            'members' => $this->identity->describeMembers($context, $ids),
            'facts' => $this->eligibility->read($context, new MembershipSelection($ids)),
            'schoolSlug' => $context->tenantSlug, 'activeYear' => $this->directory->activeYear($context)])->header('Cache-Control', 'no-store');
    }

    public function createProfile(Request $request, string $type): RedirectResponse
    {
        abort_unless(in_array($type, ['student', 'teacher'], true), 404);
        $data = $request->validate(['username' => ['required', 'string', 'max:64'], 'code' => ['nullable', 'string', 'max:64'], 'tenant_id' => ['prohibited']]);
        $data['code'] = $data['code'] ?? null;
        $this->actions->createProfile($request, $this->contexts->require(), $type, $data);

        return back()->with('status', 'Profil dibuat tidak aktif. Aktifkan setelah akun dan role memenuhi syarat.');
    }

    public function updateProfile(Request $request, string $type, string $profileUlid): RedirectResponse
    {
        abort_unless(in_array($type, ['student', 'teacher'], true), 404);
        $this->directory->requireResource($this->contexts->require(), $type, $profileUlid);
        $data = $request->validate(['code' => ['nullable', 'string', 'max:64'], 'status' => ['required', 'in:active,inactive'], 'tenant_id' => ['prohibited']]);
        $data['code'] = $data['code'] ?? null;
        $this->actions->updateProfile($request, $this->contexts->require(), $type, $profileUlid, $data);

        return back()->with('status', 'Profil diperbarui.');
    }

    private function periodRules(array $extra): array
    {
        return $extra + ['starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['required', 'date_format:Y-m-d', 'after:starts_on'], 'tenant_id' => ['prohibited']];
    }

    private function subjectRules(): array
    {
        return ['code' => ['required', 'string', 'max:32'], 'name' => ['required', 'string', 'max:150'], 'tenant_id' => ['prohibited']];
    }
}
