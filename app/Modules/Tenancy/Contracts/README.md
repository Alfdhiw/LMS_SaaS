# Tenancy — M1 contracts

> M2 update: `SchoolProfiles` is bound to `ManageSchoolProfiles`. Its update is an
> internal port requiring an already authorized, audited transaction; use the owning
> `SchoolProfileWorkflow`, not the port directly from a route. PR1-only statements
> below are historical. No HTTP feature has been added.

Tenancy owns tenants, domains and settings. `TenantRegistry` is implemented by `Actions/ManageTenantRegistry`:
pending creation, domain/settings, fresh context locks, legal lifecycle transitions and onboarding updates.
All mutation methods require an outer workflow transaction. Host/domain/status checks are server-side.

Platform owns orchestration/authorization, calls Identity for admin readiness and membership revocation, and
calls Tenancy to apply lifecycle changes. Tenancy never imports Identity/Platform. Bootstrap activation uses
the same registry contract for onboarding instead of directly writing settings from Identity.

Immutable `TenantContext`, the `TenantContextResolver` interface and the scoped store live in `App/Support/Tenancy`.
`TenantResolver` implements the shared read-only resolver; it does not authenticate members.

## M2 PR1 school profile contract

`SchoolProfiles` declares read, transaction-scoped lock and update of the verified
active tenant's own school profile. `SchoolProfileUpdate` accepts exactly name,
IANA timezone and an explicit nullable accent color; name max 200 matches existing
M1 storage. Slug, hosts, tenant ID, status, packages, logo and onboarding JSON cannot
be mass-assigned. Stored UTC events do not move when display timezone changes.

The owning workflow authenticates/authorizes through Identity before read and,
inside a write transaction, locks Tenancy first, rechecks the active actor and both
auth versions, calls update and records audit atomically. Tenancy does not import
Identity. `lock` must re-resolve tenant/domain/status and lock settings before writes;
read must also revalidate scope rather than trusting an arbitrary context object.

PR1 adds the interface and immutable DTOs, not an adapter, binding or HTTP feature.
PR2 must prove scope/permission/version checks, allowlist and audit-failure rollback
with a real implementation. Existing platform metadata actions remain unchanged.
