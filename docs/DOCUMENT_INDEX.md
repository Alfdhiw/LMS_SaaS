# DOCUMENT_INDEX — Referensi Arsitektur LMS SaaS

| Metadata | Nilai |
|---|---|
| Tanggal disusun | 5 November 2026 |
| Status indeks | Proposed — perlu review pemilik produk/technical reviewer |
| Tujuan | Satu titik referensi bagi Codex untuk versi dokumen, status persetujuan, dan prioritas sumber |

> `DOCUMENT_INDEX.md` **tidak menyetujui dokumen secara otomatis**. Sampai ada keputusan review, semua dokumen di bawah tetap berstatus **Proposed**. Dokumen yang lebih baru belum otomatis berarti disetujui untuk menjalankan migration produksi.

## A. Dokumen aktif untuk direview

| Jenis | File (relatif dari root repository) | Versi | Status | Cakupan |
|---|---|---|---|---|
| PRD Master | `docs/prd/PRD_Master_LMS_SaaS_Multi_Sekolah_v1.0.md` | 1.0 | Proposed | Visi, scope, roadmap, NFR |
| PRD Module | `docs/prd/PRD_Modul_Authentication_RBAC_v1.0.md` | 1.0 | Proposed | Login, activation, recovery, membership, policy |
| PRD Module | `docs/prd/PRD_Modul_SaaS_Central_Tenant_Management_v1.0.md` | 1.0 | Proposed | Provisioning, super-admin, tenant lifecycle |
| ADR | `docs/adr/ADR_001_Multi_Tenancy_Identity_Architecture_v1.0.md` | 1.0 | Proposed | Tenant, identity, host/session isolation |
| ADR | `docs/adr/ADR_002_Application_Architecture_Modular_Structure_v1.0.md` | 1.0 | Proposed | `app/Modules`, domain ownership, dependency boundaries |
| ERD | `docs/erd/ERD_Core_LMS_SaaS_v1.1.md` | 1.1 | Proposed | Schema terbaru untuk review; M1 delta + academic/LMS |

## B. Referensi historis

| Dokumen | Status kegunaan |
|---|---|
| ERD Core LMS SaaS **v1.0** | Historis/superseded **sebagai rancangan terbaru** oleh v1.1; tidak digunakan untuk migration baru setelah v1.1 di-accept. |
| PRD/ADR yang menautkan ERD v1.0 | Referensi dibuat sebelum v1.1; rujukan schema terbaru adalah v1.1 **setelah approval**. |
| Contoh struktur `app/Domains` pada PRD Master | Historis/konseptual; ADR-002 mengusulkan `app/Modules` sebagai satu struktur kanonis. |

## C. Urutan membaca untuk Codex

1. Root `AGENTS.md`.
2. `docs/DOCUMENT_INDEX.md` (file ini).
3. PRD Master v1.0.
4. ADR-001 v1.0.
5. ADR-002 v1.0.
6. ERD v1.1 (terbaru, Proposed).
7. PRD modul yang bersangkutan.
8. Kode, konfigurasi, migrations dan tests existing sebelum membuat perubahan.

## D. Prosedur review dan perubahan status

Untuk mengubah Proposed → Accepted, reviewer harus memeriksa kontradiksi lintas dokumen, menyepakati trade-off keamanan, dan mencatat tanggal/penanggung jawab review pada PR/issue atau catatan keputusan repository. **Codex tidak boleh menyetujui dokumennya sendiri.**

Jika satu dokumen berubah, revisi indeks ini dan tautan ke versi terbaru pada `AGENTS.md`. Riwayat tetap disimpan. Perubahan schema memerlukan ERD baru/updated; perubahan arsitektur penting memerlukan ADR baru atau amendment eksplisit.

## E. Gate pengembangan

- **M0 scaffold dan tooling:** dapat dilakukan sambil proposal direview, jika tidak mengunci schema/keputusan terbuka.
- **M1 migration final dan auth/provisioning:** tunggu baseline ADR-001, ADR-002, ERD v1.1 disetujui.
- **Pilot sekolah nyata:** hanya sesudah security, data protection, backup restore, dan uji beban terpenuhi.

## F. Perubahan yang menjadi prioritas saat review

1. Setujui atau revisi struktur `app/Modules`.
2. Setujui owner dari `tenant_provisioning_requests`, `tenant_settings`, tabel identity dan audit.
3. Setujui identitas global, membership scoped, cookie host-only, no implicit staff bypass.
4. Setujui ERD v1.1 (termasuk invite/recovery/provisioning/settings dan constraint PostgreSQL).
5. Tetapkan pilihan runtime/dependency final setelah compatibility spike M0.
