<?php

namespace App\Modules\Academic\Queries;

use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class AcademicDirectory
{
    public function requireResource(TenantContext $context, string $type, string $ulid): void
    {
        $query = match ($type) {
            'year' => DB::table('academic_years'),
            'semester' => DB::table('semesters'),
            'subject' => DB::table('subjects'),
            'student' => DB::table('student_profiles'),
            'teacher' => DB::table('teacher_profiles'),
            default => abort(404),
        };
        abort_unless($query->where('tenant_id', $context->tenantId)->where('public_ulid', $ulid)->exists(), 404);
    }

    public function activeYear(TenantContext $context): ?string
    {
        return DB::table('academic_years')->where('tenant_id', $context->tenantId)->where('status', 'active')->value('name');
    }

    public function years(TenantContext $context): LengthAwarePaginator
    {
        return DB::table('academic_years')->where('tenant_id', $context->tenantId)->orderByDesc('starts_on')->paginate(20);
    }

    public function semesters(TenantContext $context, array $yearIds): array
    {
        return DB::table('semesters')->where('tenant_id', $context->tenantId)->whereIn('academic_year_id', $yearIds)
            ->orderBy('starts_on')->get()->groupBy('academic_year_id')->all();
    }

    public function subjects(TenantContext $context): LengthAwarePaginator
    {
        return DB::table('subjects')->where('tenant_id', $context->tenantId)->orderBy('code')->paginate(20);
    }

    public function profiles(TenantContext $context, string $type): LengthAwarePaginator
    {
        if ($type === 'student') {
            return DB::table('student_profiles')->where('tenant_id', $context->tenantId)->orderBy('id')->paginate(20);
        }

        return DB::table('teacher_profiles')->where('tenant_id', $context->tenantId)->orderBy('id')->paginate(20);
    }
}
