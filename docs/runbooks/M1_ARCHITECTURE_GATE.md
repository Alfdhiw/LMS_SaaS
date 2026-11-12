# M1 ARCHITECTURE GATE — Persetujuan sebelum migration final

## Status review saat ini

- `docs/adr/ADR_001_Multi_Tenancy_Identity_Architecture_v1.0.md`: **Accepted**
- `docs/adr/ADR_002_Application_Architecture_Modular_Structure_v1.0.md`: **Accepted**
- `docs/erd/ERD_Core_LMS_SaaS_v1.1.md`: **Accepted**

Baseline arsitektur dan ERD di atas telah disetujui oleh pengguna pada 8 November 2026. Catatan ini menyelaraskan repository dengan approval tersebut; ini bukan persetujuan otomatis oleh Codex. Risiko produksi yang masih terbuka tetap harus ditangani melalui milestone dan gate terkait.

## Keputusan yang harus direview manusia

| Area | Accepted baseline | Hal yang diverifikasi |
|---|---|---|
| Tenant | Satu sekolah = satu tenant | Sesuai model komersial? |
| Data | PostgreSQL shared DB + `tenant_id` | Composite FK dan pengujian negatif realistis? |
| Identitas | `users` global + `tenant_memberships` per sekolah | Join-existing dan reset aman? |
| Session | Login per host, cookie host-only | Produksi HTTPS, session backend, multi-host revocation |
| Platform | `platform_staff` terpisah dari role sekolah | Tidak ada universal bypass akademik |
| Codebase | `app/Modules` (ADR-002) | Dependency graph dan owner tabel jelas |
| Schema M1 | 15 tabel, 4 batch migration | Contract nullable/status/idemp benar? |
| Risiko terbuka | RLS, outbox, recovery tanpa email, operasi MFA lanjutan (verifikasi manual, HTTPS, backup secret) | Ada owner, milestone, dan gate produksi |

## Approval record

```text
ADR-001: [x] Accepted [ ] Changes requested — reviewer: user (chat approval) date: 2026-11-08
ADR-002: [x] Accepted [ ] Changes requested — reviewer: user (chat approval) date: 2026-11-08
ERD v1.1: [x] Accepted [ ] Changes requested — reviewer: user (chat approval) date: 2026-11-08
Pending decisions / follow-up ADR: RLS, outbox, dan recovery tanpa email tetap menjadi follow-up produksi; operasi MFA
reset/re-enrollment sekarang memiliki endpoint terkontrol dan audit, tetapi prosedur verifikasi manual, HTTPS, session backend,
serta backup secret tetap menjadi gate produksi.
PR/Issue review URL: tidak tersedia; approval dicatat pada percakapan pengguna dan file ini.
```

## PR3 review evidence

PR3 telah direview secara teknis melalui evidence berikut:

- `M1AuthRbacTest`: 13 test, 85 assertion, lulus.
- `M1HostContextTest`: 6 test, 23 assertion, lulus sebagai bagian full suite.
- Full suite M0/M1: 33 test, 144 assertion, lulus.
- Pint: 65 file, lulus.
- Host routing: central allowlist, tenant resolver, dan unknown host negative test lulus.
- Platform permission mapping: permission platform tidak diberikan melalui role tenant; central dashboard memakai middleware permission.
- Central MFA: TOTP enrollment/challenge, secret terenkripsi, recovery code single-use, anti-replay, rate limit, dan fail-closed production.
- Central MFA reset/re-enrollment: `platform.staff.mfa_reset` hanya untuk super admin, target memakai public ULID, credential/recovery lama dihapus, `users.auth_version` dinaikkan, dan `auth.mfa.reset` dicatat atomik sebelum target diarahkan ke enrollment ulang.
- HTTP host smoke check: `localhost /` → 200, `admin.platform.test /login` → 200, `unknown.platform.test /login` → 404, dan `127.0.0.1 /` → 404.

Status: **technical review passed; PR3 disetujui pengguna pada 2026-11-12**. Manual browser verification masih menunggu environment browser yang dapat mengakses host lokal. PR4 belum dimulai dan route provisioning belum diubah.

Migration `2026_11_09_000500_create_platform_mfa_credentials` telah berstatus `Ran` pada database Docker lokal.

Approval record PR3:

```text
PR3: [x] Approved — reviewer: user (chat approval) date: 2026-11-12
Scope: Auth/RBAC, platform permission mapping, central MFA, audited MFA reset/re-enrollment.
Next gate: tunggu commit pengguna sebelum memulai PR4.
```

Setelah perubahan keputusan, update status di dokumen asli dan `docs/DOCUMENT_INDEX.md`; simpan riwayat, jangan hapus versi lama. Draft migration disesuaikan jika review mengubah schema.
