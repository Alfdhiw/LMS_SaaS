# Delta ERD v1.0 → v1.1

Tanggal: 5 November 2026. Status: **Proposed**. Dokumen lengkap: `ERD_Core_LMS_SaaS_v1.1.md`.

## Tabel baru wajib di M1

| Tabel | Pemilik | Tujuan | Kontrak khusus |
|---|---|---|---|
| `account_invitations` | Tenant | Aktivasi / join existing / bootstrap admin | Hash token, tenant FK, single-use, route activation saat pending. |
| `account_recovery_requests` | Global/tenant-aware | Password reset | Single-tenant assisted only, global user revocation, token hash. |
| `tenant_provisioning_requests` | Central | Idempotent create tenant | Unique (actor,key), canonical payload digest, row lock. |
| `tenant_settings` | Tenant | Branding + onboarding | 1:1 tenant, whitelisted non-PII JSON. |

## Kolom baru

- `users.auth_version` — integer, naik pada perubahan kredensial global.
- `tenant_memberships.auth_version` — integer, naik pada perubahan status/role membership.
- `users.email_normalized` — nullable, unique case-normalized email.
- `users.password` boleh NULL untuk `pending`, wajib terisi untuk `active`.
- `permissions.scope` — `tenant`/`platform`.
- `role_permissions.permission_scope` — check `'tenant'` + FK ke `(permissions.id, permissions.scope)`.

## Dipertahankan

Akademik (tahun ajaran, rombel, mapel, course), tugas, kuis, grade, file dan audit baseline v1.0.

## Ditunda (tidak dibuat dalam migration M1)

`tenant_status_history`, `provisioning_outbox`, `account_security_events`, `feature_definitions` dan billing gateway. Outbox perlu evaluasi ulang sebelum produksi multi-sekolah besar.

## Gate implementasi

1. Verifikasi semua foreign key komposit pada PostgreSQL.
2. Test aktivasi satu-pakai lintas host dan request konkuren.
3. Test global password reset dan membership-based session revocation.
4. Test idempotent provisioning dan tidak ada half-tenant setelah error.
5. Dapatkan persetujuan dokumen sebelum meminta Codex membuat migration.
