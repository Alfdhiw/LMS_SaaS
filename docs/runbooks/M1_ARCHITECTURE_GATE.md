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
| Risiko terbuka | MFA, RLS, outbox, recovery tanpa email | Ada owner, milestone, dan gate produksi |

## Approval record

```text
ADR-001: [x] Accepted [ ] Changes requested — reviewer: user (chat approval) date: 2026-11-08
ADR-002: [x] Accepted [ ] Changes requested — reviewer: user (chat approval) date: 2026-11-08
ERD v1.1: [x] Accepted [ ] Changes requested — reviewer: user (chat approval) date: 2026-11-08
Pending decisions / follow-up ADR: MFA, RLS, outbox, dan recovery tanpa email tetap menjadi follow-up produksi.
PR/Issue review URL: tidak tersedia; approval dicatat pada percakapan pengguna dan file ini.
```

Setelah perubahan keputusan, update status di dokumen asli dan `docs/DOCUMENT_INDEX.md`; simpan riwayat, jangan hapus versi lama. Draft migration disesuaikan jika review mengubah schema.
