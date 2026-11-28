<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait M2FoundationFixtures
{
    private const DRAFT_PATH = 'database/migrations/2026_11_28_000100_create_m2_academic_foundation.php';

    private const FOUNDATION_TABLES = ['student_profiles', 'teacher_profiles', 'academic_years', 'semesters', 'subjects'];

    private function foundationTenant(string $slug): int
    {
        return (int) DB::table('tenants')->insertGetId([
            'public_ulid' => (string) Str::ulid(), 'slug' => $slug,
            'name' => 'Sekolah Sintetis '.$slug, 'status' => 'active',
        ]);
    }

    private function foundationMember(int $tenant, string $username): int
    {
        $user = DB::table('users')->insertGetId([
            'public_ulid' => (string) Str::ulid(), 'name' => 'Akun Sintetis', 'status' => 'pending',
        ]);

        return (int) DB::table('tenant_memberships')->insertGetId([
            'tenant_id' => $tenant, 'user_id' => $user, 'local_username' => $username,
            'local_username_normalized' => $username, 'display_name' => 'Anggota Sintetis', 'status' => 'invited',
        ]);
    }

    private function foundationYear(int $tenant, array $changes = []): int
    {
        return (int) DB::table('academic_years')->insertGetId(array_replace([
            'public_ulid' => (string) Str::ulid(), 'tenant_id' => $tenant, 'name' => 'Tahun '.Str::random(8),
            'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30',
        ], $changes));
    }

    private function foundationSemester(int $tenant, int $year, array $changes = []): int
    {
        return (int) DB::table('semesters')->insertGetId(array_replace([
            'public_ulid' => (string) Str::ulid(), 'tenant_id' => $tenant, 'academic_year_id' => $year,
            'code' => 'odd', 'starts_on' => '2026-07-01', 'ends_on' => '2026-12-31',
        ], $changes));
    }

    private function foundationProfile(string $table, int $tenant, int $membership, ?string $code = null): int
    {
        return (int) DB::table($table)->insertGetId([
            'public_ulid' => (string) Str::ulid(), 'tenant_id' => $tenant, 'membership_id' => $membership,
            $table === 'student_profiles' ? 'nis' : 'employee_code' => $code,
        ]);
    }
}
