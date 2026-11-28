<?php

namespace App\Modules\Identity\Contracts;

use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** Internal ports; callers must use the verified tenant context and authorize first. */
interface AcademicIdentity
{
    /** @return array{int, int} Actor user and membership IDs. */
    public function authorize(Request $request, TenantContext $context, string $permission, bool $lock = false): array;

    /** @return array{id:int,username:string,name:string}|null Tenant-local, exact username only. */
    public function findMember(TenantContext $context, string $username, bool $lock = false): ?array;

    /** @param list<int> $ids @return array<int, array{username:string,name:string}> */
    public function describeMembers(TenantContext $context, array $ids): array;
}
