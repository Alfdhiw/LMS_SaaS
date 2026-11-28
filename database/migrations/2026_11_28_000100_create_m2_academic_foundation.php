<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Approved local additive batch. Application rollback is intentionally prohibited.
return new class extends Migration
{
    public function up(): void
    {
        $this->requireAllowedUpTarget();

        Schema::create('student_profiles', function (Blueprint $table): void {
            $table->id();
            $table->char('public_ulid', 26)->unique('m2_student_public_ulid_unique');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('membership_id');
            $table->string('nis', 64)->nullable();
            $table->string('status', 20)->default('inactive');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id'], 'm2_student_tenant_id_unique');
            $table->unique(['tenant_id', 'membership_id'], 'm2_student_membership_unique');
            $table->index(['tenant_id', 'status'], 'm2_student_status_index');
            $table->foreign('tenant_id', 'm2_student_tenant_fk')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'membership_id'], 'm2_student_membership_fk')
                ->references(['tenant_id', 'id'])->on('tenant_memberships')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE student_profiles ADD CONSTRAINT m2_student_status CHECK (status IN ('active','inactive'))");
        DB::statement("ALTER TABLE student_profiles ADD CONSTRAINT m2_student_nis_nonblank CHECK (nis IS NULL OR btrim(nis) <> '')");
        DB::statement('CREATE UNIQUE INDEX m2_student_nis_unique ON student_profiles (tenant_id, nis) WHERE nis IS NOT NULL');

        Schema::create('teacher_profiles', function (Blueprint $table): void {
            $table->id();
            $table->char('public_ulid', 26)->unique('m2_teacher_public_ulid_unique');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('membership_id');
            $table->string('employee_code', 64)->nullable();
            $table->string('status', 20)->default('inactive');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id'], 'm2_teacher_tenant_id_unique');
            $table->unique(['tenant_id', 'membership_id'], 'm2_teacher_membership_unique');
            $table->index(['tenant_id', 'status'], 'm2_teacher_status_index');
            $table->foreign('tenant_id', 'm2_teacher_tenant_fk')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'membership_id'], 'm2_teacher_membership_fk')
                ->references(['tenant_id', 'id'])->on('tenant_memberships')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE teacher_profiles ADD CONSTRAINT m2_teacher_status CHECK (status IN ('active','inactive'))");
        DB::statement("ALTER TABLE teacher_profiles ADD CONSTRAINT m2_teacher_code_nonblank CHECK (employee_code IS NULL OR btrim(employee_code) <> '')");
        DB::statement('CREATE UNIQUE INDEX m2_teacher_code_unique ON teacher_profiles (tenant_id, employee_code) WHERE employee_code IS NOT NULL');

        Schema::create('academic_years', function (Blueprint $table): void {
            $table->id();
            $table->char('public_ulid', 26)->unique('m2_year_public_ulid_unique');
            $table->unsignedBigInteger('tenant_id');
            $table->string('name', 40);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('draft');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id'], 'm2_year_tenant_id_unique');
            $table->unique(['tenant_id', 'name'], 'm2_year_name_unique');
            $table->index(['tenant_id', 'status'], 'm2_year_status_index');
            $table->foreign('tenant_id', 'm2_year_tenant_fk')->references('id')->on('tenants')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE academic_years ADD CONSTRAINT m2_year_status CHECK (status IN ('draft','active','closed'))");
        DB::statement('ALTER TABLE academic_years ADD CONSTRAINT m2_year_dates CHECK (starts_on < ends_on)');
        DB::statement("CREATE UNIQUE INDEX m2_year_one_active ON academic_years (tenant_id) WHERE status = 'active'");

        Schema::create('semesters', function (Blueprint $table): void {
            $table->id();
            $table->char('public_ulid', 26)->unique('m2_semester_public_ulid_unique');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('academic_year_id');
            $table->string('code', 4);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('draft');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id'], 'm2_semester_tenant_id_unique');
            $table->unique(['tenant_id', 'academic_year_id', 'id'], 'm2_semester_year_id_unique');
            $table->unique(['tenant_id', 'academic_year_id', 'code'], 'm2_semester_code_unique');
            $table->index(['tenant_id', 'status'], 'm2_semester_status_index');
            $table->foreign('tenant_id', 'm2_semester_tenant_fk')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'academic_year_id'], 'm2_semester_year_fk')
                ->references(['tenant_id', 'id'])->on('academic_years')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE semesters ADD CONSTRAINT m2_semester_code CHECK (code IN ('odd','even'))");
        DB::statement("ALTER TABLE semesters ADD CONSTRAINT m2_semester_status CHECK (status IN ('draft','active','closed'))");
        DB::statement('ALTER TABLE semesters ADD CONSTRAINT m2_semester_dates CHECK (starts_on < ends_on)');
        DB::statement("CREATE UNIQUE INDEX m2_semester_one_active ON semesters (tenant_id, academic_year_id) WHERE status = 'active'");

        Schema::create('subjects', function (Blueprint $table): void {
            $table->id();
            $table->char('public_ulid', 26)->unique('m2_subject_public_ulid_unique');
            $table->unsignedBigInteger('tenant_id');
            $table->string('code', 32);
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id'], 'm2_subject_tenant_id_unique');
            $table->unique(['tenant_id', 'code'], 'm2_subject_code_unique');
            $table->index(['tenant_id', 'is_active'], 'm2_subject_active_index');
            $table->foreign('tenant_id', 'm2_subject_tenant_fk')->references('id')->on('tenants')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE subjects ADD CONSTRAINT m2_subject_code_nonblank CHECK (btrim(code) <> '')");
        DB::statement("ALTER TABLE subjects ADD CONSTRAINT m2_subject_name_nonblank CHECK (btrim(name) <> '')");
    }

    public function down(): void
    {
        $this->requireTestDatabase();
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('semesters');
        Schema::dropIfExists('academic_years');
        Schema::dropIfExists('teacher_profiles');
        Schema::dropIfExists('student_profiles');
    }

    private function requireTestDatabase(): void
    {
        if (! app()->environment('testing') || DB::connection()->getDriverName() !== 'pgsql'
            || (DB::selectOne('select current_database() as name')->name ?? null) !== 'lms_saas_test') {
            throw new RuntimeException('M2 rollback is restricted to the isolated test database.');
        }
    }

    private function requireAllowedUpTarget(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('M2 migration requires PostgreSQL.');
        }
        $target = DB::selectOne('select current_database() as name, current_user as role');
        if ((app()->environment('testing') && $target->name === 'lms_saas_test')
            || (app()->environment('local') && $target->name === 'lms_saas' && $target->role === 'lms_user')) {
            return;
        }

        throw new RuntimeException('M2 migration refuses this environment, database or role.');
    }
};
