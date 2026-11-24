# Identity — M1 contracts

Identity owns users, membership/RBAC, invitation/recovery and authentication credentials (including central MFA).
`BootstrapAdminAccounts` creates/rotates bootstrap credentials inside the caller's transaction with the pending
tenant locked; it never provisions a tenant. `TenantMemberships` supplies tenant-scoped readiness/offboarding and
revocation. `PermissionCatalogue` exposes a read-only check for platform permission keys, not platform staff rights.

Platform orchestrates these contracts and Tenancy atomically. `ChangeOwnPassword` writes only the authenticated
user's global credential, requires current-password proof, revokes auth versions/recovery and audits atomically.
`AuthSessionManager` belongs to Identity; shared session keys and read-only staff access ports remain in Support.
Identity never imports Platform. Tenant metadata/settings writes go through `Tenancy/Contracts/TenantRegistry`.
