<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Auth\AuthSession;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class M2AcademicHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_periods_are_tenant_scoped_and_validate_state_and_dates(): void
    {
        $a = $this->school('http-a');
        $b = $this->school('http-b');
        [$admin, $membership] = $this->member($a, 'admin', 'school_admin');
        $this->loginSchool($a, $admin, $membership, 'http-a');
        $yearInput = ['name' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30'];
        $this->post('http://http-a.platform.test/academic/years', $yearInput)->assertRedirect();
        $year = DB::table('academic_years')->where('tenant_id', $a)->first();
        $this->assertSame('draft', $year->status);
        $foreign = (string) Str::ulid();
        DB::table('academic_years')->insert(['public_ulid' => $foreign, 'tenant_id' => $b, 'name' => 'Lain', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $this->post('http://http-a.platform.test/academic/years/'.$foreign.'/status', ['status' => 'active'])->assertNotFound();
        $this->post('http://http-a.platform.test/academic/years', $yearInput + ['tenant_id' => $b])->assertSessionHasErrors('tenant_id');
        $this->post('http://http-a.platform.test/academic/years/'.$year->public_ulid.'/semesters', ['code' => 'odd', 'starts_on' => '2025-01-01', 'ends_on' => '2026-12-31'])->assertSessionHasErrors('starts_on');
        $this->post('http://http-a.platform.test/academic/years/'.$year->public_ulid.'/semesters', ['code' => 'odd', 'starts_on' => '2026-07-01', 'ends_on' => '2026-12-31'])->assertRedirect();
        $this->post('http://http-a.platform.test/academic/years/'.$year->public_ulid.'/semesters', ['code' => 'even', 'starts_on' => '2026-12-31', 'ends_on' => '2027-06-30'])->assertSessionHasErrors('starts_on');
        $semester = DB::table('semesters')->where('tenant_id', $a)->first();
        $this->post('http://http-a.platform.test/academic/semesters/'.$semester->public_ulid.'/status', ['status' => 'active'])->assertStatus(409);
        $this->post('http://http-a.platform.test/academic/years/'.$year->public_ulid.'/status', ['status' => 'active'])->assertRedirect();
        $this->post('http://http-a.platform.test/academic/semesters/'.$semester->public_ulid.'/status', ['status' => 'active'])->assertRedirect();
        $this->post('http://http-a.platform.test/academic/years/'.$year->public_ulid.'/status', ['status' => 'closed'])->assertStatus(409);
        $this->post('http://http-a.platform.test/academic/semesters/'.$semester->public_ulid.'/status', ['status' => 'closed'])->assertRedirect();
        $this->post('http://http-a.platform.test/academic/years/'.$year->public_ulid.'/status', ['status' => 'closed'])->assertRedirect();
        $this->post('http://http-a.platform.test/academic/years/'.$year->public_ulid.'/status', ['status' => 'active'])->assertStatus(409);
        $this->get('http://http-a.platform.test/academic/periods')->assertOk()->assertSee('2026/2027')->assertDontSee('Lain');
        $this->assertDatabaseHas('tenant_audit_logs', ['tenant_id' => $a, 'action' => 'academic.year_created']);
        [$adminB, $membershipB] = $this->member($b, 'admin-b', 'school_admin');
        $this->loginSchool($b, $adminB, $membershipB, 'http-b');
        $this->get('http://http-b.platform.test/academic/periods')->assertOk()->assertSee('Lain')->assertDontSee('2026/2027');
        $this->post('http://http-b.platform.test/academic/subjects', ['code' => 'MAT', 'name' => 'Mapel Sekolah B'])->assertRedirect();
        $this->assertDatabaseHas('subjects', ['tenant_id' => $b, 'code' => 'MAT']);
    }

    public function test_profiles_subjects_and_school_update_enforce_membership_and_permissions(): void
    {
        $a = $this->school('profile-http-a');
        $b = $this->school('profile-http-b');
        [$admin, $membership] = $this->member($a, 'admin', 'school_admin');
        [$student, $studentMember] = $this->member($a, 'siswa', 'student');
        [$teacher, $teacherMember] = $this->member($a, 'guru', 'teacher');
        $this->member($b, 'siswa-b', 'student');
        $this->loginSchool($a, $admin, $membership, 'profile-http-a');
        $this->get('http://profile-http-a.platform.test/school/profile')->assertOk();
        $this->post('http://profile-http-a.platform.test/school/profile', ['name' => 'Sekolah Baru', 'timezone' => 'UTC', 'accent_color' => '#123456'])->assertRedirect();
        $this->assertDatabaseHas('tenants', ['id' => $a, 'name' => 'Sekolah Baru']);
        $this->assertDatabaseMissing('tenants', ['id' => $b, 'name' => 'Sekolah Baru']);
        $this->post('http://profile-http-a.platform.test/academic/subjects', ['code' => 'MAT', 'name' => 'Matematika'])->assertRedirect();
        $this->get('http://profile-http-a.platform.test/academic/subjects')->assertOk()->assertSee('Matematika');
        $this->post('http://profile-http-a.platform.test/academic/profiles/student', ['username' => 'siswa-b'])->assertSessionHasErrors('username');
        $this->post('http://profile-http-a.platform.test/academic/profiles/student', ['username' => 'siswa', 'code' => '001'])->assertRedirect();
        $profile = DB::table('student_profiles')->where('tenant_id', $a)->where('membership_id', $studentMember)->first();
        $this->assertSame('inactive', $profile->status);
        $this->post('http://profile-http-a.platform.test/academic/profiles/student/'.$profile->public_ulid, ['status' => 'active', 'code' => '001'])->assertRedirect();
        $this->assertDatabaseHas('student_profiles', ['id' => $profile->id, 'status' => 'active', 'nis' => '001']);
        $this->get('http://profile-http-a.platform.test/academic/profiles/student')->assertOk()->assertSee('siswa')->assertDontSee('siswa-b');
        DB::table('tenant_memberships')->where('id', $studentMember)->update(['status' => 'suspended', 'auth_version' => 2]);
        $this->post('http://profile-http-a.platform.test/academic/profiles/student/'.$profile->public_ulid, ['status' => 'active', 'code' => '001'])->assertSessionHasErrors('status');
        $this->loginSchool($a, $teacher, $teacherMember, 'profile-http-a');
        $this->get('http://profile-http-a.platform.test/academic/periods')->assertForbidden();
        $this->post('http://profile-http-a.platform.test/academic/profiles/student', ['username' => 'siswa'])->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $student, 'status' => 'active']);
    }

    public function test_cross_tenant_resources_invited_profiles_and_revocation_fail_closed(): void
    {
        $a = $this->school('boundary-a');
        $b = $this->school('boundary-b');
        [$admin, $membership] = $this->member($a, 'admin', 'school_admin');
        [$invited, $invitedMember] = $this->member($a, 'invited', 'student');
        [, $foreignMember] = $this->member($b, 'foreign', 'student');
        DB::table('users')->where('id', $invited)->update(['status' => 'pending']);
        DB::table('tenant_memberships')->where('id', $invitedMember)->update(['status' => 'invited']);
        $foreignSubject = (string) Str::ulid();
        $foreignProfile = (string) Str::ulid();
        DB::table('subjects')->insert(['public_ulid' => $foreignSubject, 'tenant_id' => $b, 'code' => 'FOREIGN', 'name' => 'Rahasia sintetis']);
        DB::table('student_profiles')->insert(['public_ulid' => $foreignProfile, 'tenant_id' => $b, 'membership_id' => $foreignMember]);
        $this->loginSchool($a, $admin, $membership, 'boundary-a');
        $this->post('http://boundary-a.platform.test/academic/subjects/'.$foreignSubject, ['code' => 'T', 'name' => 'Ubah', 'is_active' => 1])->assertNotFound();
        $this->post('http://boundary-a.platform.test/academic/subjects/'.$foreignSubject, [])->assertNotFound();
        $this->post('http://boundary-a.platform.test/academic/profiles/student/'.$foreignProfile, ['status' => 'active'])->assertNotFound();
        $this->post('http://boundary-a.platform.test/academic/profiles/student/'.$foreignProfile, [])->assertNotFound();
        $response = $this->get('http://boundary-a.platform.test/academic/subjects')->assertDontSee('Rahasia sintetis');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->post('http://boundary-a.platform.test/academic/profiles/student', ['username' => 'invited'])->assertRedirect();
        $profile = DB::table('student_profiles')->where('tenant_id', $a)->where('membership_id', $invitedMember)->first();
        $this->assertSame('inactive', $profile->status);
        $this->post('http://boundary-a.platform.test/academic/profiles/student/'.$profile->public_ulid, ['status' => 'active'])->assertSessionHasErrors('status');
        $this->assertDatabaseHas('student_profiles', ['id' => $profile->id, 'status' => 'inactive']);
        $this->assertDatabaseHas('subjects', ['tenant_id' => $b, 'public_ulid' => $foreignSubject, 'code' => 'FOREIGN']);
        DB::table('tenant_memberships')->where('id', $membership)->increment('auth_version');
        $this->post('http://boundary-a.platform.test/academic/subjects', ['code' => 'DENY', 'name' => 'Denied'])->assertRedirect('/login');
        $this->assertDatabaseMissing('subjects', ['tenant_id' => $a, 'code' => 'DENY']);
    }

    public function test_audit_failure_rolls_back_academic_mutation(): void
    {
        $tenant = $this->school('audit-academic');
        [$admin, $membership] = $this->member($tenant, 'admin', 'school_admin');
        $this->loginSchool($tenant, $admin, $membership, 'audit-academic');
        DB::statement("CREATE FUNCTION pg_temp.m2_reject_academic_audit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'synthetic audit failure'; END $$");
        DB::statement('CREATE TRIGGER m2_reject_academic BEFORE INSERT ON tenant_audit_logs FOR EACH ROW EXECUTE FUNCTION pg_temp.m2_reject_academic_audit()');
        $this->post('http://audit-academic.platform.test/academic/subjects', ['code' => 'ROLLBACK', 'name' => 'Sintetis'])->assertStatus(500);
        $this->assertDatabaseMissing('subjects', ['tenant_id' => $tenant, 'code' => 'ROLLBACK']);
    }

    public function test_academic_write_requires_csrf(): void
    {
        $tenant = $this->school('csrf-academic');
        [$admin, $membership] = $this->member($tenant, 'admin', 'school_admin');
        $this->loginSchool($tenant, $admin, $membership, 'csrf-academic');
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->post('http://csrf-academic.platform.test/academic/subjects', ['code' => 'DENY', 'name' => 'Sintetis'])->assertStatus(419);
        $this->assertDatabaseMissing('subjects', ['tenant_id' => $tenant, 'code' => 'DENY']);
    }

    private function school(string $slug): int
    {
        $tenant = (int) DB::table('tenants')->insertGetId(['public_ulid' => (string) Str::ulid(), 'slug' => $slug, 'name' => 'Sekolah Sintetis '.$slug, 'status' => 'active']);
        DB::table('tenant_domains')->insert(['tenant_id' => $tenant, 'hostname' => $slug.'.platform.test', 'status' => 'active', 'verified_at' => now()]);
        DB::table('tenant_settings')->insert(['tenant_id' => $tenant, 'onboarding_json' => '{}']);

        return $tenant;
    }

    private function member(int $tenant, string $username, string $role): array
    {
        $user = (int) DB::table('users')->insertGetId(['public_ulid' => (string) Str::ulid(), 'name' => 'Nama Sintetis', 'password' => Hash::make('Synthetic!234'), 'status' => 'active']);
        $membership = (int) DB::table('tenant_memberships')->insertGetId(['tenant_id' => $tenant, 'user_id' => $user, 'local_username' => $username,
            'local_username_normalized' => $username, 'display_name' => 'Anggota Sintetis', 'status' => 'active']);
        DB::table('membership_roles')->insert(['tenant_id' => $tenant, 'membership_id' => $membership, 'role_id' => DB::table('roles')->where('key', $role)->value('id')]);

        return [$user, $membership];
    }

    private function loginSchool(int $tenant, int $user, int $membership, string $slug): void
    {
        $this->actingAs(User::query()->findOrFail($user), 'web')->withSession([
            AuthSession::AREA => 'tenant', AuthSession::HOST => $slug.'.platform.test', AuthSession::USER_ID => $user,
            AuthSession::TENANT_ID => $tenant, AuthSession::MEMBERSHIP_ID => $membership,
            AuthSession::USER_AUTH_VERSION => 1, AuthSession::MEMBERSHIP_AUTH_VERSION => 1,
        ]);
    }
}
