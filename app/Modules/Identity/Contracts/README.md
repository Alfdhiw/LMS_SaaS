# Identity — M1 contracts

> M2 update: `MembershipEligibilityReader` is now bound to a same-tenant query.
> `SchoolProfileWorkflow` is the internal, session/permission-checked entry point
> for audited school profile changes. The PR1-only description below is historical;
> no HTTP route or user-facing Academic directory has been added.

Identity owns users, membership/RBAC, invitation/recovery and authentication credentials (including central MFA).
`BootstrapAdminAccounts` creates/rotates bootstrap credentials inside the caller's transaction with the pending
tenant locked; it never provisions a tenant. `TenantMemberships` supplies tenant-scoped readiness/offboarding and
revocation. `PermissionCatalogue` exposes a read-only check for platform permission keys, not platform staff rights.

Platform orchestrates these contracts and Tenancy atomically. `ChangeOwnPassword` writes only the authenticated
user's global credential, requires current-password proof, revokes auth versions/recovery and audits atomically.
`AuthSessionManager` belongs to Identity; shared session keys and read-only staff access ports remain in Support.
Identity never imports Platform. Tenant metadata/settings writes go through `Tenancy/Contracts/TenantRegistry`.

## M2 PR1 eligibility contract

`MembershipEligibilityReader` declares fresh, same-tenant facts for server-resolved,
previously authorized membership IDs. `MembershipSelection` caps each batch at 100,
rejects non-integer/invalid IDs, and canonicalizes order. This is an internal query
bound, not the school's CSV quota; a 500-row import can use bounded batches.
`MembershipEligibility` exposes IDs, tenant roles, statuses and both auth versions,
not names, credentials, email or complete Eloquent models. `isActive()` is not an
Academic permission/profile/assignment decision; version snapshots require a fresh
read per protected operation and are not an authorization cache.

PR1 declares the interface/DTOs only: no implementation, container binding, directory
query or route is added. PR2 must implement and test actor authorization, tenant
filtering before lookup, omitted foreign IDs, revocation, and bounded batch reads.
Name/username selectors need their own authorized Identity read adapter; this port
does not provide account linking or a global student directory. Invitation/reissue
for CSV remains PR5. Identity must never depend back on Academic.
