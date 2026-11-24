# Platform — M1 orchestration boundary

Platform owns staff records and provisioning idempotency. It authorizes central actions through
`Services/PlatformPermissionChecker`; school roles cannot grant these permissions or access student data.

Provisioning/lifecycle/resend use Tenancy and Identity contracts/actions inside one database transaction;
Platform never directly mutates tenant, membership or invitation tables. Directory/dashboard/audit read models
remain explicitly read-only and return selected metadata/aggregates, not a privileged write bypass.

`PlatformStaffAccess` implements the read-only `Support/Auth/CentralStaffAccess` port so Identity can verify or
exclude staff without importing Platform. Shared workflow failures use `Support/Exceptions/OperationException`.
`M1ArchitectureTest` guards dependency direction and direct Query Builder/model writes for current M1 table owners.
