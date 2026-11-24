# Shared tenant context — M1

`TenantContext` is immutable. `TenantContextStore` is scoped and cleared in HTTP middleware `finally` blocks.
`TenantContextResolver` is an infrastructure port implemented by Tenancy; Support never imports business modules.

Queued tenant work can use `Jobs/UseTenantContext($tenantId, $hostname)` with locators supplied by a trusted
dispatching workflow. Every execution re-resolves the verified active domain/tenant; mismatched, unknown, revoked
or suspended scopes fail closed before `handle`. Context is cleared at entry and in `finally`, including failure.
Tests use Laravel's real sync queue pipeline to exercise A -> failure -> B, not a queue fake.

This middleware validates scope, not user permission/resource ownership. Each real domain job must additionally
validate its actor/membership/status and resource through its owning action/contracts at execution time.
Never trust request tenant IDs or serialized context as authorization; do not serialize passwords or invitation tokens.
