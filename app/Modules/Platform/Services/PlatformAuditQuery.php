<?php

namespace App\Modules\Platform\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class PlatformAuditQuery
{
    /** @param array<string, mixed> $filters */
    public function search(array $filters): LengthAwarePaginator
    {
        // Never select metadata, target ID, email, IP or session data for this viewer.
        $query = DB::table('platform_audit_logs as audit')
            ->leftJoin('users as actor', 'actor.id', '=', 'audit.actor_user_id')
            ->leftJoin('tenants as tenant', 'tenant.id', '=', 'audit.tenant_id');

        if (! empty($filters['actor'])) {
            $query->where('actor.public_ulid', $filters['actor']);
        }
        if (! empty($filters['tenant'])) {
            $query->where('tenant.public_ulid', $filters['tenant']);
        }
        if (! empty($filters['action'])) {
            $query->where('audit.action', $filters['action']);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('audit.created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('audit.created_at', '<=', $filters['to']);
        }
        if (($filters['result'] ?? null) === 'failed') {
            $query->where(function ($q): void {
                $q->where('audit.action', 'like', '%.failed')->orWhere('audit.action', 'like', '%\\_failed');
            });
        } elseif (($filters['result'] ?? null) === 'succeeded') {
            $query->where(function ($q): void {
                $q->where('audit.action', 'like', '%.succeeded')->orWhere('audit.action', 'like', '%\\_sent');
            });
        }

        return $query->orderByDesc('audit.created_at')->orderByDesc('audit.id')
            ->paginate(25, ['audit.id', 'audit.action', 'audit.created_at', 'actor.public_ulid as actor_ulid',
                'tenant.public_ulid as tenant_ulid', 'tenant.name as tenant_name'])
            ->withQueryString()
            ->through(static function (object $row): array {
                $action = preg_match('/\A[a-z0-9_.-]{1,120}\z/', $row->action) === 1 ? $row->action : 'event_redacted';

                return [
                    'action' => $action,
                    'created_at' => $row->created_at,
                    'actor_ulid' => $row->actor_ulid,
                    'tenant_ulid' => $row->tenant_ulid,
                    'tenant_name' => $row->tenant_name,
                    'result' => match (true) {
                        str_ends_with($action, '.failed'), str_ends_with($action, '_failed') => 'failed',
                        str_ends_with($action, '.succeeded'), str_ends_with($action, '_sent') => 'succeeded',
                        default => 'other',
                    },
                ];
            });
    }
}
