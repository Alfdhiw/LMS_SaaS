# Tenancy — M1 contracts

Tenancy owns tenants, domains and settings. `TenantRegistry` is implemented by `Actions/ManageTenantRegistry`:
pending creation, domain/settings, fresh context locks, legal lifecycle transitions and onboarding updates.
All mutation methods require an outer workflow transaction. Host/domain/status checks are server-side.

Platform owns orchestration/authorization, calls Identity for admin readiness and membership revocation, and
calls Tenancy to apply lifecycle changes. Tenancy never imports Identity/Platform. Bootstrap activation uses
the same registry contract for onboarding instead of directly writing settings from Identity.

Immutable `TenantContext`, the `TenantContextResolver` interface and the scoped store live in `App/Support/Tenancy`.
`TenantResolver` implements the shared read-only resolver; it does not authenticate members.
