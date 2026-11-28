# DOCUMENT_INDEX — Referensi Arsitektur LMS SaaS

| Metadata | Nilai |
|---|---|
| Tanggal disusun | 5 November 2026 |
| Status indeks | Accepted — baseline arsitektur dan ERD disetujui 8 November 2026 |
| Status milestone — 25 November 2026 | PR6 Approved; M1 Closed by owner untuk lokal/sintetis; evidence browser/HTTPS belum lengkap; bukan production approval |
| Bukti browser publik — 25 November 2026 | 11 pemeriksaan HTTP/tamu Chrome lulus; screenshot dan manifest tersedia; bukan login/HTTPS acceptance lengkap |
| Perencanaan M2 — 27 November 2026 | Branch Milestone-2 / 4b29461; dokumen awal PR0 disusun sebagai Proposed |
| Approval M2 — 28 November 2026 | PR0 Approved oleh pengguna; PRD Academic/amendment/plan v0.1 Accepted; penyusunan PR1 diizinkan, belum dimulai; DDL aplikasi belum diizinkan |
| Tujuan | Satu titik referensi bagi Codex untuk versi dokumen, status persetujuan, dan prioritas sumber |

> `DOCUMENT_INDEX.md` mencatat hasil review dan **tidak menyetujui dokumen secara otomatis**. Status `Accepted` merujuk pada approval record manusia: baseline M1 pada `docs/runbooks/M1_ARCHITECTURE_GATE.md`, dan dokumen M2 pada `docs/runbooks/M2_ARCHITECTURE_GATE.md`. Perubahan tanggal atau urutan file bukan approval; gate produksi M1 dan izin DDL per batch tetap berlaku.

## A. Dokumen aktif untuk direview

| Jenis | File (relatif dari root repository) | Versi | Status | Cakupan |
|---|---|---|---|---|
| PRD Master | `docs/prd/PRD_Master_LMS_SaaS_Multi_Sekolah_v1.0.md` | 1.0 | Accepted | Visi, scope, roadmap, NFR |
| PRD Module | `docs/prd/PRD_Modul_Authentication_RBAC_v1.0.md` | 1.0 | Accepted | Login, activation, recovery, membership, policy |
| PRD Module | `docs/prd/PRD_Modul_SaaS_Central_Tenant_Management_v1.0.md` | 1.0 | Accepted | Provisioning, super-admin, tenant lifecycle |
| ADR | `docs/adr/ADR_001_Multi_Tenancy_Identity_Architecture_v1.0.md` | 1.0 | Accepted | Tenant, identity, host/session isolation |
| ADR | `docs/adr/ADR_002_Application_Architecture_Modular_Structure_v1.0.md` | 1.0 | Accepted | `app/Modules`, domain ownership, dependency boundaries |
| ERD | `docs/erd/ERD_Core_LMS_SaaS_v1.1.md` | 1.1 | Accepted | Schema terbaru untuk review; M1 delta + academic/LMS |
| PRD Module M2 | [PRD Academic Administration](prd/PRD_Modul_Academic_Administration_v0.1.md) | 0.1 | Accepted — 28 November 2026 | ACAD-001–007, course/roster dan keputusan D01–D06 |
| ERD amendment M2 | [Academic M2](erd/ERD_M2_Academic_Amendment_v0.1.md) | 0.1 | Accepted — 28 November 2026 | Melengkapi rincian 10 tabel Academic Core v1.1; bukan izin DDL |

Untuk M2, Core v1.1 dan amendment Accepted digunakan bersama. Rincian M2 yang
diperjelas amendment mengikuti amendment; M1/domain lain tetap mengikuti Core.
Sinkronisasi anotasi dictionary/diagram aktif dikerjakan pada PR1 sebelum migration final.

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
6. ERD Core v1.1 dan amendment Academic v0.1 untuk M2 (keduanya Accepted).
7. PRD modul yang bersangkutan.
8. Untuk M2, `docs/runbooks/M2_MASTER_PLAN.md` dan `M2_ARCHITECTURE_GATE.md` untuk status tahap/DDL.
9. Kode, konfigurasi, migrations dan tests existing sebelum membuat perubahan.

## D. Prosedur review dan perubahan status

Untuk mengubah Proposed → Accepted, reviewer harus memeriksa kontradiksi lintas dokumen, menyepakati trade-off keamanan, dan mencatat tanggal/penanggung jawab review pada PR/issue atau catatan keputusan repository. **Codex tidak boleh menyetujui dokumennya sendiri.**

Jika satu dokumen berubah, revisi indeks ini dan tautan ke versi terbaru pada `AGENTS.md`. Riwayat tetap disimpan. Perubahan schema memerlukan ERD baru/updated; perubahan arsitektur penting memerlukan ADR baru atau amendment eksplisit.

## E. Gate pengembangan

- **M0 scaffold dan tooling:** dapat dilakukan sambil proposal direview, jika tidak mengunci schema/keputusan terbuka.
- **M1 migration final dan auth/provisioning:** baseline ADR-001, ADR-002, dan ERD v1.1 telah disetujui; lanjutkan sesuai urutan PR dan acceptance checklist M1.
- **M2:** PR0 Approved pada 28 November 2026; penyusunan PR1 diizinkan, belum dimulai. PR2–PR6 dan DDL aplikasi menunggu izin tersendiri sesuai gate M2.
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
dan verifikasi manual multi-host. PR3 disetujui pengguna pada 2026-11-12; PR4 disetujui pengguna pada 2026-11-18.

## H. Evidence review PR4

PR4 telah diimplementasikan pada branch `Milestone-1` setelah commit PR3. Cakupan meliputi provisioning tenant atomik,
idempotency actor/key/payload, invitation bootstrap admin tenant-bound, activation host pending yang single-use,
lifecycle transition dengan readiness check, platform permission middleware, dan negative security tests.
Approval manusia untuk PR4 diberikan melalui percakapan pada 2026-11-18; pengguna mengizinkan pengerjaan PR5.

Rekomendasi PR4 nomor 1-4 juga telah diimplementasikan sebagai hardening lanjutan: delivery email invitation dan resend
tanpa reprovisioning, advisory lock dan test dua proses untuk concurrency idempotency, script HTTP host smoke dengan
`curl --resolve`, serta UI Blade central untuk directory/detail/provisioning/lifecycle. Durable `provisioning_outbox`
tetap ditunda karena merupakan perubahan schema/ERD tersendiri; delivery failure diaudit dan dapat diulang melalui resend.

## I. Status PR5

PR5 telah disetujui. Role/status membership, undangan akun baru, join existing dengan undangan dan re-autentikasi,
recovery mandiri melalui email terverifikasi, serta UI undangan offline tersedia untuk review. Pada 2026-11-19 pengguna
menyetujui rancangan recovery siswa tanpa email dengan dua sesi privat dan verifikasi tatap muka: admin tidak menerima
token siswa. Pengguna menyetujui PR5 dan mengizinkan PR6 pada 2026-11-21. Pengujian otomatis akhir PR5 lulus;
verifikasi browser HTTPS masih terbuka. PR6 kemudian disetujui pengguna pada 25 November 2026; catatan penutupan,
bukti test dan gate tersisa ada di `docs/runbooks/M1_ARCHITECTURE_GATE.md` serta bagian M di bawah.

## J. Status hardening autentikasi PostgreSQL lokal

Pengguna menyetujui pemasangan kandidat HBA dan reload PostgreSQL lokal pada 2026-11-23. Kandidat tujuh rule dengan
SCRAM sudah diterapkan pada volume lokal yang direview; hasil rollout dan bukti pemulihan dicatat di
`docs/runbooks/M1_ARCHITECTURE_GATE.md`. Ini adalah perubahan operasional tanpa perubahan ERD/schema, berlaku untuk
instalasi lokal tersebut, dan tidak mengubah status review PR6 atau menjadi approval produksi.

## K. Final review M1 — 24 November 2026

`docs/runbooks/M1_FINAL_REVIEW.md` mencatat review commit `b07ebdf182080f184065caf5b60a2b81c9efafaa`,
hasil pengujian ulang, tautan CI, temuan requirement/arsitektur, serta syarat penutupan. Checklist penerimaan dan
`M1_TEST_MATRIX.md` sekarang memisahkan bukti yang tersedia dari cakupan parsial atau belum diuji.

Status saat review awal 24 November: **changes requested; PR6 belum disetujui, M1 belum complete, M2 belum dimulai**. Permintaan melaksanakan
final review bukan approval otomatis atas hasilnya. Baseline PRD/ADR/ERD yang Accepted tidak berubah;
penyelesaian gap harus mengikuti baseline tersebut atau keputusan perubahan eksplisit dari pemilik.

## L. Remediasi final review M1 — 24 November 2026

Pengguna menyetujui implementasi rekomendasi 1–3 dengan jawaban "oke saya setujui". Cakupan: perubahan password
mandiri AUTH-06, pemulihan boundary owner/dependency ADR-002, dan perluasan test kegagalan/session/job tenant.
Implementasi mengikuti baseline Accepted yang sama, tanpa schema/migration/Docker baru atau pengecualian ADR.

Evidence terbaru ada pada §8 `docs/runbooks/M1_FINAL_REVIEW.md`; checklist/matrix dan README contract telah
diselaraskan dengan alur yang tersedia. Bagian K dan review awal tetap merupakan snapshot historis, bukan status
bahwa fitur tersebut masih hilang setelah remediasi. Remediasi kini di-commit sebagai `015e792`; hasil CI terbaru
dicatat di bagian M. CI commit `b07ebdf` sendiri bukan bukti kode remediasi.

Persetujuan implementasi pada 24 November **bukan approval hasil PR6 atau penutupan M1**; approval akhir diberikan
terpisah pada 25 November di bawah. Demonstrasi browser empat host/HTTPS belum mempunyai evidence.

## M. Approval akhir PR6/M1 — 25 November 2026

Pengguna menyatakan: "saya approval akhir PR6/M1, tolong kamu perbarui semua". Record manusia ini menyetujui PR6 dan
menutup milestone M1 (**Closed by owner**) untuk pengembangan lokal dengan fixture sintetis pada commit kode
`015e792f350536db14f4be049624e6d0e82e95b2`, branch `Milestone-1`. Ini bukan persetujuan otomatis oleh Codex.

[M0 checks run 45356905502](https://github.com/Alfdhiw/LMS_SaaS/actions/runs/45356905502) untuk commit tersebut
berstatus success, termasuk job `smoke` dan `private-database-helper`; metadata dibaca 25 November 2026.
Regresi lokal 100 test/791 assertion dan runtime 2 test/51 assertion berasal dari pengujian 24 November, bukan
pengulangan baru saat pencatatan approval. Evidence terperinci: §9 [M1_FINAL_REVIEW.md](runbooks/M1_FINAL_REVIEW.md).

Approval milestone dan kelengkapan evidence teknis dibedakan: browser empat host/HTTPS tetap belum dibuktikan;
insiden kehilangan/rebuild data PR5 tetap tercatat, bukan dianggap tidak pernah terjadi. Baseline acceptance,
PRD/ADR/ERD dan gate produksi tidak diberi waiver atau diubah. Tidak ada izin data siswa nyata, deployment produksi,
M2, tag/release, commit/push/merge otomatis. Pembaruan approval ini belum mempunyai commit/CI tersendiri.

Label status pada enam baseline aktif dan referensi header ERD lama diselaraskan dengan status Accepted yang sudah
tercatat; tidak mengubah keputusan/desain. File baseline, AGENTS dan master plan tetap di-ignore untuk Git sesuai
aturan yang ada. Approval/status kanonis di gate/indeks/laporan tetap dilacak Git; paket overlay historis tidak diubah.

## N. Bukti browser publik HTTP — 25 November 2026

§10 [M1_FINAL_REVIEW.md](runbooks/M1_FINAL_REVIEW.md) mencatat 11 pemeriksaan browser Chrome headless yang
benar-benar dijalankan pada aplikasi commit `78385da5b25c2a69508b1df770dfcae15d6d7164`. Bukti tersedia sebagai
11 screenshot publik dan [manifest SHA-256](evidence/m1-browser/public-http-20261125T081910548Z-5e84915c/manifest.json).
Runner `tests/Support/m1_browser_guest_evidence.mjs` dapat diulang tanpa package baru; tidak login/mengirim form.

Ini memperbarui catatan lama "tidak ada bukti browser" hanya untuk scope publik/tamu HTTP. Browser bawaan gagal
sebelum navigasi; fallback adalah Chrome lokal terpasang. Evidence login/MFA, sesi terautentikasi lintas sekolah,
ganti password, recovery dua sesi dan HTTPS tepercaya **belum tersedia**. Approval M1 tidak berubah; checkbox
demonstrasi lengkap tidak dicentang. Izin trust sertifikat Windows telah diminta, belum dianggap diberikan.

## O. Approval PR0 M2 — 28 November 2026

Dokumen PR0 disusun sebagai Proposed pada 27 November 2026, branch `Milestone-2`, baseline kode `4b29461`.
Pada 28 November pengguna menyatakan: "saya approval semua untuk PR0 di M2". Ini menerima hasil PR0,
scope ACAD-001–007, D01–D06, PRD/amendment/plan v0.1 dan izin penyusunan PR1. Bukti, reviewer, tanggal dan
checkbox ada pada [M2 Architecture Gate](runbooks/M2_ARCHITECTURE_GATE.md). Enam baseline M1 tetap Accepted;
amendment hanya melengkapi rincian M2 dan tidak mengganti keseluruhan Core v1.1.

| Jenis | Dokumen | Versi | Status |
|---|---|---|---|
| PRD modul | [Academic Administration](prd/PRD_Modul_Academic_Administration_v0.1.md) | 0.1 | Accepted — 28 November 2026 |
| ERD amendment | [Academic M2](erd/ERD_M2_Academic_Amendment_v0.1.md) | 0.1 | Accepted; pelengkap M2 untuk Core v1.1 |
| Runbook | [M2 Master Plan](runbooks/M2_MASTER_PLAN.md) | 0.1 | Accepted; satu PR per review |
| Approval | [M2 Architecture Gate](runbooks/M2_ARCHITECTURE_GATE.md) | 0.1 | PR0 Approved; persiapan PR1 diizinkan, DDL aplikasi belum diizinkan |

M1 tetap Closed by owner; evidence browser login/HTTPS dan gate data nyata/produksi tidak berubah. Catatan lama
"M2 belum diizinkan" merujuk waktu penutupan M1, sebelum perencanaan dan approval PR0 terbaru. PR1 belum
dimulai pada pencatatan approval ini. Dictionary/diagram dan migration plan/preflight harus direview pada PR1
sebelum izin DDL aplikasi; PR2–PR6 memerlukan izin per tahap. Dokumen M2/gate tetap dipilih secara selektif
untuk Git; baseline desain lokal dan file privat lain tetap mengikuti ignore existing. Tidak ada perubahan kode,
schema/database, Docker, permission atau Git commit/push/merge saat pencatatan approval ini.
