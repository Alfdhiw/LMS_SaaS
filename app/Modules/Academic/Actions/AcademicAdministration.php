<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Policies\ProfileEligibilityPolicy;
use App\Modules\Identity\Contracts\AcademicIdentity;
use App\Modules\Identity\Contracts\MembershipEligibilityReader;
use App\Modules\Identity\Data\MembershipSelection;
use App\Modules\Tenancy\Contracts\TenantRegistry;
use App\Support\Audit\AuthAuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Commands use the resolved host, serialize on tenant, recheck identity, and audit atomically. */
final class AcademicAdministration
{
    public function __construct(
        private readonly TenantRegistry $tenants,
        private readonly AcademicIdentity $identity,
        private readonly MembershipEligibilityReader $eligibility,
        private readonly ProfileEligibilityPolicy $profilePolicy,
        private readonly AuthAuditLogger $audit,
    ) {}

    public function createYear(Request $request, TenantContext $context, array $input): void
    {
        $this->mutate($request, $context, 'academic.manage', 'academic.year_created', function () use ($context, $input): string {
            if (DB::table('academic_years')->where('tenant_id', $context->tenantId)->where('name', $input['name'])->exists()) {
                throw ValidationException::withMessages(['name' => 'Nama tahun ajaran sudah digunakan.']);
            }
            $ulid = (string) Str::ulid();
            DB::table('academic_years')->insert(['public_ulid' => $ulid, 'tenant_id' => $context->tenantId,
                'name' => $input['name'], 'starts_on' => $input['starts_on'], 'ends_on' => $input['ends_on'],
                'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);

            return $ulid;
        });
    }

    public function createSemester(Request $request, TenantContext $context, string $yearUlid, array $input): void
    {
        $this->mutate($request, $context, 'academic.manage', 'academic.semester_created', function () use ($context, $yearUlid, $input): string {
            $year = $this->year($context, $yearUlid);
            abort_if($year->status === 'closed', 409, 'Tahun ajaran sudah ditutup.');
            if ($input['starts_on'] < $year->starts_on || $input['ends_on'] > $year->ends_on) {
                throw ValidationException::withMessages(['starts_on' => 'Tanggal semester harus berada dalam tahun ajaran.']);
            }
            if (DB::table('semesters')->where('tenant_id', $context->tenantId)->where('academic_year_id', $year->id)
                ->where('code', $input['code'])->exists()) {
                throw ValidationException::withMessages(['code' => 'Semester sudah ada pada tahun ajaran ini.']);
            }
            if (DB::table('semesters')->where('tenant_id', $context->tenantId)->where('academic_year_id', $year->id)
                ->where('starts_on', '<=', $input['ends_on'])->where('ends_on', '>=', $input['starts_on'])->exists()) {
                throw ValidationException::withMessages(['starts_on' => 'Tanggal semester tidak boleh tumpang tindih.']);
            }
            $ulid = (string) Str::ulid();
            DB::table('semesters')->insert(['public_ulid' => $ulid, 'tenant_id' => $context->tenantId,
                'academic_year_id' => $year->id, 'code' => $input['code'], 'starts_on' => $input['starts_on'],
                'ends_on' => $input['ends_on'], 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);

            return $ulid;
        });
    }

    public function updateYear(Request $request, TenantContext $context, string $ulid, array $input): void
    {
        $this->mutate($request, $context, 'academic.manage', 'academic.year_updated', function () use ($context, $ulid, $input): string {
            $year = $this->year($context, $ulid);
            abort_unless($year->status === 'draft', 409, 'Hanya tahun draft dapat diubah.');
            if (DB::table('semesters')->where('tenant_id', $context->tenantId)->where('academic_year_id', $year->id)->exists()) {
                abort(409, 'Tahun yang sudah memiliki semester tidak dapat diubah.');
            }
            if (Schema::hasTable('classrooms') && DB::table('classrooms')->where('tenant_id', $context->tenantId)->where('academic_year_id', $year->id)->exists()) {
                abort(409, 'Tahun yang sudah memiliki rombel tidak dapat diubah.');
            }
            if (DB::table('academic_years')->where('tenant_id', $context->tenantId)->where('name', $input['name'])->where('id', '<>', $year->id)->exists()) {
                throw ValidationException::withMessages(['name' => 'Nama tahun ajaran sudah digunakan.']);
            }
            DB::table('academic_years')->where('tenant_id', $context->tenantId)->where('id', $year->id)->update([
                'name' => $input['name'], 'starts_on' => $input['starts_on'], 'ends_on' => $input['ends_on'], 'updated_at' => now(),
            ]);

            return $ulid;
        });
    }

    public function updateSemester(Request $request, TenantContext $context, string $ulid, array $input): void
    {
        $this->mutate($request, $context, 'academic.manage', 'academic.semester_updated', function () use ($context, $ulid, $input): string {
            $semester = DB::table('semesters')->where('tenant_id', $context->tenantId)->where('public_ulid', $ulid)->lockForUpdate()->first();
            abort_if($semester === null, 404);
            $year = DB::table('academic_years')->where('tenant_id', $context->tenantId)->where('id', $semester->academic_year_id)->lockForUpdate()->first();
            abort_unless($year !== null && $year->status !== 'closed' && $semester->status === 'draft', 409, 'Hanya semester draft pada tahun terbuka yang dapat diubah.');
            if (Schema::hasTable('courses') && DB::table('courses')->where('tenant_id', $context->tenantId)->where('semester_id', $semester->id)->exists()) {
                abort(409, 'Semester yang sudah memiliki course tidak dapat diubah.');
            }
            if ($input['starts_on'] < $year->starts_on || $input['ends_on'] > $year->ends_on) {
                throw ValidationException::withMessages(['starts_on' => 'Tanggal semester harus berada dalam tahun ajaran.']);
            }
            if (DB::table('semesters')->where('tenant_id', $context->tenantId)->where('academic_year_id', $year->id)
                ->where('id', '<>', $semester->id)->where('starts_on', '<=', $input['ends_on'])->where('ends_on', '>=', $input['starts_on'])->exists()) {
                throw ValidationException::withMessages(['starts_on' => 'Tanggal semester tidak boleh tumpang tindih.']);
            }
            DB::table('semesters')->where('tenant_id', $context->tenantId)->where('id', $semester->id)->update([
                'starts_on' => $input['starts_on'], 'ends_on' => $input['ends_on'], 'updated_at' => now(),
            ]);

            return $ulid;
        });
    }

    public function transitionYear(Request $request, TenantContext $context, string $ulid, string $target): void
    {
        $this->mutate($request, $context, 'academic.manage', 'academic.year_'.$target, function () use ($context, $ulid, $target): string {
            $year = $this->year($context, $ulid);
            $this->transitionAllowed($year->status, $target);
            if ($target === 'active' && DB::table('academic_years')->where('tenant_id', $context->tenantId)->where('status', 'active')->exists()) {
                abort(409, 'Tutup tahun ajaran aktif terlebih dahulu.');
            }
            if ($target === 'closed' && DB::table('semesters')->where('tenant_id', $context->tenantId)
                ->where('academic_year_id', $year->id)->where('status', '<>', 'closed')->exists()) {
                abort(409, 'Semua semester harus ditutup terlebih dahulu.');
            }
            DB::table('academic_years')->where('tenant_id', $context->tenantId)->where('id', $year->id)
                ->update(['status' => $target, 'updated_at' => now()]);

            return $ulid;
        });
    }

    public function transitionSemester(Request $request, TenantContext $context, string $ulid, string $target): void
    {
        $this->mutate($request, $context, 'academic.manage', 'academic.semester_'.$target, function () use ($context, $ulid, $target): string {
            $semester = DB::table('semesters')->where('tenant_id', $context->tenantId)->where('public_ulid', $ulid)->lockForUpdate()->first();
            abort_if($semester === null, 404);
            $year = DB::table('academic_years')->where('tenant_id', $context->tenantId)->where('id', $semester->academic_year_id)->lockForUpdate()->first();
            abort_if($year === null || $year->status === 'closed', 409);
            $this->transitionAllowed($semester->status, $target);
            if ($target === 'active' && ($year->status !== 'active' || DB::table('semesters')->where('tenant_id', $context->tenantId)
                ->where('academic_year_id', $year->id)->where('status', 'active')->exists())) {
                abort(409, 'Tahun harus aktif dan semester aktif sebelumnya harus ditutup.');
            }
            if ($target === 'closed' && Schema::hasTable('courses') && DB::table('courses')->where('tenant_id', $context->tenantId)
                ->where('semester_id', $semester->id)->where('status', '<>', 'closed')->exists()) {
                abort(409, 'Semua course semester harus ditutup terlebih dahulu.');
            }
            DB::table('semesters')->where('tenant_id', $context->tenantId)->where('id', $semester->id)
                ->update(['status' => $target, 'updated_at' => now()]);

            return $ulid;
        });
    }

    public function createSubject(Request $request, TenantContext $context, array $input): void
    {
        $this->mutate($request, $context, 'subjects.manage', 'academic.subject_created', function () use ($context, $input): string {
            if (DB::table('subjects')->where('tenant_id', $context->tenantId)->where('code', $input['code'])->exists()) {
                throw ValidationException::withMessages(['code' => 'Kode mata pelajaran sudah digunakan.']);
            }
            $ulid = (string) Str::ulid();
            DB::table('subjects')->insert(['public_ulid' => $ulid, 'tenant_id' => $context->tenantId,
                'code' => $input['code'], 'name' => $input['name'], 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

            return $ulid;
        });
    }

    public function updateSubject(Request $request, TenantContext $context, string $ulid, array $input): void
    {
        $this->mutate($request, $context, 'subjects.manage', 'academic.subject_updated', function () use ($context, $ulid, $input): string {
            $subject = DB::table('subjects')->where('tenant_id', $context->tenantId)->where('public_ulid', $ulid)->lockForUpdate()->first();
            abort_if($subject === null, 404);
            if (DB::table('subjects')->where('tenant_id', $context->tenantId)->where('code', $input['code'])->where('id', '<>', $subject->id)->exists()) {
                throw ValidationException::withMessages(['code' => 'Kode mata pelajaran sudah digunakan.']);
            }
            DB::table('subjects')->where('tenant_id', $context->tenantId)->where('id', $subject->id)->update([
                'code' => $input['code'], 'name' => $input['name'], 'is_active' => $input['is_active'], 'updated_at' => now(),
            ]);

            return $ulid;
        });
    }

    public function createProfile(Request $request, TenantContext $context, string $type, array $input): void
    {
        abort_unless(in_array($type, ['student', 'teacher'], true), 404);
        $this->mutate($request, $context, 'academic.manage', 'academic.'.$type.'_created', function () use ($request, $context, $type, $input): string {
            $this->identity->authorize($request, $context, 'members.view', true);
            $member = $this->identity->findMember($context, $input['username'], true);
            if ($member === null) {
                throw ValidationException::withMessages(['username' => 'Anggota sekolah tidak ditemukan.']);
            }
            $facts = $this->eligibility->read($context, new MembershipSelection([$member['id']]))[$member['id']] ?? null;
            if ($facts === null || ! $facts->hasRole($type)) {
                throw ValidationException::withMessages(['username' => 'Role anggota tidak sesuai jenis profil.']);
            }
            $ulid = (string) Str::ulid();
            $base = ['public_ulid' => $ulid, 'tenant_id' => $context->tenantId, 'membership_id' => $member['id'],
                'status' => 'inactive', 'created_at' => now(), 'updated_at' => now()];
            if ($type === 'student') {
                if (DB::table('student_profiles')->where('tenant_id', $context->tenantId)->where('membership_id', $member['id'])->exists()) {
                    throw ValidationException::withMessages(['username' => 'Profil siswa sudah ada.']);
                }
                if ($input['code'] !== null && DB::table('student_profiles')->where('tenant_id', $context->tenantId)->where('nis', $input['code'])->exists()) {
                    throw ValidationException::withMessages(['code' => 'NIS sudah digunakan.']);
                }
                DB::table('student_profiles')->insert($base + ['nis' => $input['code']]);
            } else {
                if (DB::table('teacher_profiles')->where('tenant_id', $context->tenantId)->where('membership_id', $member['id'])->exists()) {
                    throw ValidationException::withMessages(['username' => 'Profil guru sudah ada.']);
                }
                if ($input['code'] !== null && DB::table('teacher_profiles')->where('tenant_id', $context->tenantId)->where('employee_code', $input['code'])->exists()) {
                    throw ValidationException::withMessages(['code' => 'Kode pegawai sudah digunakan.']);
                }
                DB::table('teacher_profiles')->insert($base + ['employee_code' => $input['code']]);
            }

            return $ulid;
        });
    }

    public function updateProfile(Request $request, TenantContext $context, string $type, string $ulid, array $input): void
    {
        abort_unless(in_array($type, ['student', 'teacher'], true), 404);
        $this->mutate($request, $context, 'academic.manage', 'academic.'.$type.'_updated', function () use ($request, $context, $type, $ulid, $input): string {
            $this->identity->authorize($request, $context, 'members.view', true);
            $profile = $type === 'student'
                ? DB::table('student_profiles')->where('tenant_id', $context->tenantId)->where('public_ulid', $ulid)->lockForUpdate()->first()
                : DB::table('teacher_profiles')->where('tenant_id', $context->tenantId)->where('public_ulid', $ulid)->lockForUpdate()->first();
            abort_if($profile === null, 404);
            $memberId = (int) $profile->membership_id;
            $description = $this->identity->describeMembers($context, [$memberId])[$memberId] ?? null;
            $member = $description === null ? null : $this->identity->findMember($context, $description['username'], true);
            abort_if($member === null || $member['id'] !== $memberId, 404);
            $facts = $this->eligibility->read($context, new MembershipSelection([$memberId]))[$memberId] ?? null;
            if ($input['status'] === 'active' && ($facts === null || ! $this->profilePolicy->mayActivate($facts, $context->tenantId, $type))) {
                throw ValidationException::withMessages(['status' => 'Akun, membership, dan role harus aktif dan sesuai sebelum profil diaktifkan.']);
            }
            if ($type === 'student') {
                if ($input['code'] !== null && DB::table('student_profiles')->where('tenant_id', $context->tenantId)->where('nis', $input['code'])->where('id', '<>', $profile->id)->exists()) {
                    throw ValidationException::withMessages(['code' => 'NIS sudah digunakan.']);
                }
                DB::table('student_profiles')->where('tenant_id', $context->tenantId)->where('id', $profile->id)->update([
                    'nis' => $input['code'], 'status' => $input['status'], 'updated_at' => now(),
                ]);
            } else {
                if ($input['code'] !== null && DB::table('teacher_profiles')->where('tenant_id', $context->tenantId)->where('employee_code', $input['code'])->where('id', '<>', $profile->id)->exists()) {
                    throw ValidationException::withMessages(['code' => 'Kode pegawai sudah digunakan.']);
                }
                DB::table('teacher_profiles')->where('tenant_id', $context->tenantId)->where('id', $profile->id)->update([
                    'employee_code' => $input['code'], 'status' => $input['status'], 'updated_at' => now(),
                ]);
            }

            return $ulid;
        });
    }

    private function mutate(Request $request, TenantContext $context, string $permission, string $event, callable $work): void
    {
        DB::transaction(function () use ($request, $context, $permission, $event, $work): void {
            $this->tenants->lockContext($context);
            [$actor, $membership] = $this->identity->authorize($request, $context, $permission, true);
            $target = $work();
            $this->audit->tenant($request, $context, $event, $actor, $membership, $target, [], 'academic');
        });
    }

    private function year(TenantContext $context, string $ulid): object
    {
        $year = DB::table('academic_years')->where('tenant_id', $context->tenantId)->where('public_ulid', $ulid)->lockForUpdate()->first();
        abort_if($year === null, 404);

        return $year;
    }

    private function transitionAllowed(string $current, string $target): void
    {
        abort_unless(($current === 'draft' && in_array($target, ['active', 'closed'], true))
            || ($current === 'active' && $target === 'closed'), 409, 'Perubahan status tidak diizinkan.');
    }
}
