# DOCUMENT_INDEX — Referensi Arsitektur LMS SaaS

| Metadata | Nilai |
|---|---|
| Tanggal disusun | 5 November 2026 |
| Status indeks | Accepted — baseline arsitektur dan ERD disetujui 8 November 2026 |
| Tujuan | Satu titik referensi bagi Codex untuk versi dokumen, status persetujuan, dan prioritas sumber |

> `DOCUMENT_INDEX.md` mencatat hasil review dan **tidak menyetujui dokumen secara otomatis**. Status `Accepted` di bawah merujuk pada approval record di `docs/runbooks/M1_ARCHITECTURE_GATE.md`, bukan pada perubahan tanggal atau urutan file. Migration produksi tetap mengikuti acceptance gate dan runbook M1.

## A. Dokumen aktif untuk direview

| Jenis | File (relatif dari root repository) | Versi | Status | Cakupan |
|---|---|---|---|---|
| PRD Master | `docs/prd/PRD_Master_LMS_SaaS_Multi_Sekolah_v1.0.md` | 1.0 | Accepted | Visi, scope, roadmap, NFR |
| PRD Module | `docs/prd/PRD_Modul_Authentication_RBAC_v1.0.md` | 1.0 | Accepted | Login, activation, recovery, membership, policy |
| PRD Module | `docs/prd/PRD_Modul_SaaS_Central_Tenant_Management_v1.0.md` | 1.0 | Accepted | Provisioning, super-admin, tenant lifecycle |
| ADR | `docs/adr/ADR_001_Multi_Tenancy_Identity_Architecture_v1.0.md` | 1.0 | Accepted | Tenant, identity, host/session isolation |
| ADR | `docs/adr/ADR_002_Application_Architecture_Modular_Structure_v1.0.md` | 1.0 | Accepted | `app/Modules`, domain ownership, dependency boundaries |
| ERD | `docs/erd/ERD_Core_LMS_SaaS_v1.1.md` | 1.1 | Accepted | Schema terbaru untuk review; M1 delta + academic/LMS |

## B. Referensi historis

| Dokumen | Status kegunaan |
|---|---|
| ERD Core LMS SaaS **v1.0** | Historis/superseded **sebagai rancangan terbaru** oleh v1.1; tidak digunakan untuk migration baru setelah v1.1 di-accept. |
| PRD/ADR yang menautkan ERD v1.0 | Referensi dibuat sebelum v1.1; rujukan schema terbaru adalah ERD v1.1 yang **Accepted**. |
| Contoh struktur `app/Domains` pada PRD Master | Historis/konseptual; ADR-002 mengusulkan `app/Modules` sebagai satu struktur kanonis. |

## C. Urutan membaca untuk Codex

1. Root `AGENTS.md`.
2. `docs/DOCUMENT_INDEX.md` (file ini).
3. PRD Master v1.0.
4. ADR-001 v1.0.
5. ADR-002 v1.0.
6. ERD v1.1 (terbaru, Accepted).
7. PRD modul yang bersangkutan.
8. Kode, konfigurasi, migrations dan tests existing sebelum membuat perubahan.

## D. Prosedur review dan perubahan status

Untuk mengubah Proposed → Accepted, reviewer harus memeriksa kontradiksi lintas dokumen, menyepakati trade-off keamanan, dan mencatat tanggal/penanggung jawab review pada PR/issue atau catatan keputusan repository. **Codex tidak boleh menyetujui dokumennya sendiri.**

Jika satu dokumen berubah, revisi indeks ini dan tautan ke versi terbaru pada `AGENTS.md`. Riwayat tetap disimpan. Perubahan schema memerlukan ERD baru/updated; perubahan arsitektur penting memerlukan ADR baru atau amendment eksplisit.

## E. Gate pengembangan

- **M0 scaffold dan tooling:** dapat dilakukan sambil proposal direview, jika tidak mengunci schema/keputusan terbuka.
- **M1 migration final dan auth/provisioning:** baseline ADR-001, ADR-002, dan ERD v1.1 telah disetujui; lanjutkan sesuai urutan PR dan acceptance checklist M1.
- **Pilot sekolah nyata:** hanya sesudah security, data protection, backup restore, dan uji beban terpenuhi.

## F. Perubahan yang menjadi prioritas saat review

1. Setujui atau revisi struktur `app/Modules`.
2. Setujui owner dari `tenant_provisioning_requests`, `tenant_settings`, tabel identity dan audit.
3. Setujui identitas global, membership scoped, cookie host-only, no implicit staff bypass.
4. Setujui ERD v1.1 (termasuk invite/recovery/provisioning/settings dan constraint PostgreSQL).
5. Tetapkan pilihan runtime/dependency final setelah compatibility spike M0.

## G. Evidence review PR3

PR3 telah melalui technical review dan regression test pada branch `Milestone-1`. Cakupannya meliputi host-bound authentication, tenant RBAC, platform permission mapping, dan central MFA. Hasil test dan status approval manusia dicatat di `docs/runbooks/M1_ARCHITECTURE_GATE.md`; Codex tidak mengubah status menjadi accepted tanpa approval reviewer manusia.

Central MFA sudah tersedia untuk enrollment, challenge TOTP, serta reset/re-enrollment terkontrol yang diaudit melalui permission
`platform.staff.mfa_reset`. Risiko produksi yang tersisa adalah verifikasi manual, HTTPS, session backend, backup secret terenkripsi,
dan verifikasi manual multi-host. PR3 disetujui pengguna pada 2026-11-12; PR4 menunggu commit pengguna dan belum dimulai.
