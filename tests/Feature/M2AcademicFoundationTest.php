<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\M2FoundationFixtures;
use Tests\TestCase;

final class M2AcademicFoundationTest extends TestCase
{
    use M2FoundationFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => self::DRAFT_PATH, '--force' => true])->assertExitCode(0);
    }

    public function test_draft_requires_explicit_path_and_is_not_in_default_discovery(): void
    {
        $defaultFiles = app('migrator')->getMigrationFiles([database_path('migrations')]);
        $this->assertCount(5, $defaultFiles);
        foreach ($defaultFiles as $path) {
            $this->assertStringNotContainsString('pending_m2', $path);
        }
        foreach (self::FOUNDATION_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        foreach (['classrooms', 'classroom_enrollments', 'courses', 'course_teachers', 'course_enrollments'] as $table) {
            $this->assertFalse(Schema::hasTable($table), 'PR3/PR4 schema must not be introduced in PR1.');
        }
    }

    public function test_postgresql_catalog_has_validated_restrict_fks_candidate_keys_and_partial_indexes(): void
    {
        $constraints = DB::table('pg_constraint as c')->join('pg_class as t', 't.oid', '=', 'c.conrelid')
            ->join('pg_namespace as n', 'n.oid', '=', 't.relnamespace')
            ->where('n.nspname', 'public')->whereIn('t.relname', self::FOUNDATION_TABLES)
            ->get(['c.conname', 'c.contype', 'c.convalidated', 'c.confdeltype']);
        $this->assertCount(8, $constraints->where('contype', 'f'));
        foreach ($constraints as $constraint) {
            $this->assertTrue((bool) $constraint->convalidated, $constraint->conname);
            $this->assertLessThanOrEqual(63, strlen($constraint->conname));
            if ($constraint->contype === 'f') {
                $this->assertSame('r', $constraint->confdeltype, $constraint->conname);
            }
        }
        $names = $constraints->pluck('conname')->all();
        foreach (['m2_student_membership_fk', 'm2_teacher_membership_fk', 'm2_semester_year_fk',
            'm2_semester_year_id_unique', 'm2_student_tenant_id_unique', 'm2_teacher_tenant_id_unique',
            'm2_year_tenant_id_unique', 'm2_semester_tenant_id_unique', 'm2_subject_tenant_id_unique'] as $name) {
            $this->assertContains($name, $names);
        }
        $indexes = DB::table('pg_indexes')->where('schemaname', 'public')->whereIn('tablename', self::FOUNDATION_TABLES)
            ->get()->keyBy('indexname');
        foreach (['m2_student_nis_unique', 'm2_teacher_code_unique', 'm2_year_one_active', 'm2_semester_one_active'] as $name) {
            $this->assertTrue($indexes->has($name));
            $this->assertStringContainsString('CREATE UNIQUE INDEX', $indexes[$name]->indexdef);
            $this->assertStringContainsString('WHERE', $indexes[$name]->indexdef);
        }
        foreach (self::FOUNDATION_TABLES as $table) {
            $columns = collect(DB::select('SELECT column_name, data_type, is_nullable, character_maximum_length FROM information_schema.columns WHERE table_schema = ? AND table_name = ?', ['public', $table]))->keyBy('column_name');
            $this->assertSame('NO', $columns['tenant_id']->is_nullable);
            $this->assertSame('bigint', $columns['tenant_id']->data_type);
            $this->assertSame('NO', $columns['public_ulid']->is_nullable);
            $this->assertSame(26, (int) $columns['public_ulid']->character_maximum_length);
            $this->assertSame('timestamp with time zone', $columns['created_at']->data_type);
            $this->assertSame('timestamp with time zone', $columns['updated_at']->data_type);
        }
    }

    public function test_profiles_reject_membership_of_another_tenant_and_default_to_inactive(): void
    {
        $a = $this->foundationTenant('m2-profile-a');
        $b = $this->foundationTenant('m2-profile-b');
        $member = $this->foundationMember($a, 'sintetis');
        foreach (['student_profiles' => 'm2_student_membership_fk', 'teacher_profiles' => 'm2_teacher_membership_fk'] as $table => $constraint) {
            $this->rejects(fn () => $this->foundationProfile($table, $b, $member), '23503', $constraint);
            $id = $this->foundationProfile($table, $a, $member, '0000123');
            $this->assertSame('inactive', DB::table($table)->where('id', $id)->value('status'));
            $this->assertSame('0000123', DB::table($table)->where('id', $id)->value($table === 'student_profiles' ? 'nis' : 'employee_code'));
            $this->rejects(fn () => $this->foundationProfile($table, $a, $member), '23505');
        }
    }

    public function test_optional_profile_identifiers_allow_nulls_and_other_tenants_but_not_local_duplicates(): void
    {
        $a = $this->foundationTenant('m2-codes-a');
        $b = $this->foundationTenant('m2-codes-b');
        foreach (['student_profiles', 'teacher_profiles'] as $table) {
            $members = [];
            foreach (range(1, 4) as $number) {
                $members[] = $this->foundationMember($a, $table.$number);
            }
            $this->foundationProfile($table, $a, $members[0]);
            $this->foundationProfile($table, $a, $members[1]);
            $this->foundationProfile($table, $a, $members[2], '001');
            $this->rejects(fn () => $this->foundationProfile($table, $a, $members[3], '001'), '23505');
            $this->foundationProfile($table, $b, $this->foundationMember($b, $table), '001');
            $this->assertSame(4, DB::table($table)->count());
            $this->rejects(fn () => $this->foundationProfile($table, $a, $members[3], '  '), '23514');
        }
    }

    public function test_active_year_is_unique_per_tenant_and_dates_and_status_are_checked(): void
    {
        $a = $this->foundationTenant('m2-year-a');
        $b = $this->foundationTenant('m2-year-b');
        $year = $this->foundationYear($a, ['name' => '2026/2027', 'status' => 'active']);
        $this->assertGreaterThan(0, $year);
        $this->rejects(fn () => $this->foundationYear($a, ['status' => 'active']), '23505', 'm2_year_one_active');
        $this->foundationYear($b, ['name' => '2026/2027', 'status' => 'active']);
        $draft = $this->foundationYear($a);
        $this->assertSame('draft', DB::table('academic_years')->where('id', $draft)->value('status'));
        $this->rejects(fn () => $this->foundationYear($a, ['name' => '2026/2027']), '23505', 'm2_year_name_unique');
        foreach ([['starts_on' => '2027-06-30'], ['ends_on' => '2026-06-30'], ['status' => 'reopened']] as $changes) {
            $this->rejects(fn () => $this->foundationYear($a, $changes), '23514');
        }
    }

    public function test_semester_cannot_reference_foreign_year_or_create_two_active_periods(): void
    {
        $a = $this->foundationTenant('m2-sem-a');
        $b = $this->foundationTenant('m2-sem-b');
        $year = $this->foundationYear($a, ['status' => 'active']);
        $this->rejects(fn () => $this->foundationSemester($b, $year), '23503', 'm2_semester_year_fk');
        $this->foundationSemester($a, $year, ['status' => 'active']);
        $this->rejects(fn () => $this->foundationSemester($a, $year, ['code' => 'even', 'status' => 'active']), '23505', 'm2_semester_one_active');
        $this->foundationSemester($a, $year, ['code' => 'even']);
        $this->rejects(fn () => $this->foundationSemester($a, $year), '23505', 'm2_semester_code_unique');
        $otherYear = $this->foundationYear($a);
        foreach ([['code' => 'bad'], ['status' => 'open'], ['starts_on' => '2026-12-31']] as $changes) {
            $this->rejects(fn () => $this->foundationSemester($a, $otherYear, $changes), '23514');
        }
    }

    public function test_subject_codes_are_tenant_local_and_blank_fields_are_rejected(): void
    {
        $a = $this->foundationTenant('m2-subject-a');
        $b = $this->foundationTenant('m2-subject-b');
        $insert = fn (int $tenant, array $changes = []) => DB::table('subjects')->insert(array_replace([
            'public_ulid' => (string) Str::ulid(), 'tenant_id' => $tenant, 'code' => 'MAT', 'name' => 'Mapel Sintetis',
        ], $changes));
        $insert($a);
        $insert($b);
        $this->assertTrue((bool) DB::table('subjects')->where('tenant_id', $a)->value('is_active'));
        $this->rejects(fn () => $insert($a), '23505', 'm2_subject_code_unique');
        $this->rejects(fn () => $insert($a, ['code' => ' ']), '23514', 'm2_subject_code_nonblank');
        $this->rejects(fn () => $insert($a, ['code' => 'IPA', 'name' => ' ']), '23514', 'm2_subject_name_nonblank');
    }

    public function test_academic_history_parents_are_restricted_not_cascaded(): void
    {
        $tenant = $this->foundationTenant('m2-restrict');
        $membership = $this->foundationMember($tenant, 'history');
        $this->foundationProfile('student_profiles', $tenant, $membership);
        $this->rejects(fn () => DB::table('tenant_memberships')->where('id', $membership)->delete(), '23503', 'm2_student_membership_fk');
        $year = $this->foundationYear($tenant);
        $this->foundationSemester($tenant, $year);
        $this->rejects(fn () => DB::table('academic_years')->where('id', $year)->delete(), '23503', 'm2_semester_year_fk');
        $this->assertSame(1, DB::table('student_profiles')->count());
    }

    public function test_explicit_draft_up_down_up_preserves_existing_m1_schema(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::DRAFT_PATH, '--step' => 1, '--force' => true])->assertExitCode(0);
        foreach (self::FOUNDATION_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $this->assertTrue(Schema::hasTable('tenant_memberships'));
        $this->assertSame(5, DB::table('migrations')->count());
        $this->artisan('migrate', ['--path' => self::DRAFT_PATH, '--force' => true])->assertExitCode(0);
        foreach (self::FOUNDATION_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $this->assertSame(6, DB::table('migrations')->count());
    }

    public function test_draft_rejects_non_testing_environment_before_any_schema_change(): void
    {
        $migration = require database_path('migrations/pending_m2/2026_11_28_000100_create_m2_academic_foundation.php');
        app()->instance('env', 'local');
        try {
            foreach (['up', 'down'] as $operation) {
                try {
                    $migration->{$operation}();
                    $this->fail('Application DDL must remain blocked.');
                } catch (\RuntimeException $exception) {
                    $this->assertStringContainsString('test-only', $exception->getMessage());
                }
            }
        } finally {
            app()->instance('env', 'testing');
        }
        foreach (self::FOUNDATION_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
    }

    private function rejects(callable $mutation, string $state, ?string $constraint = null): void
    {
        try {
            DB::transaction($mutation);
            $this->fail('PostgreSQL must reject the invalid mutation.');
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0]);
            if ($constraint !== null) {
                $this->assertStringContainsString($constraint, $exception->getMessage());
            }
        }
    }
}
