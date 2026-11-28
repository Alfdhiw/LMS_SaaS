# M2 Master Plan Administrasi Sekolah

> **Pembaruan 28 November 2026:** PR1 disetujui; DDL M2-A lokal diizinkan dan telah
> diterapkan setelah preflight. Workflow/UI PR2 disiapkan untuk review; ini belum
> merupakan approval PR2 atau bukti seluruh exit review PR2 terpenuhi. Lihat
> [gate terkini](M2_ARCHITECTURE_GATE.md). Status lama di bawah adalah snapshot.

| Metadata | Nilai |
|---|---|
| Versi | 0.1 |
| Tanggal | 27 November 2026 |
| Status | Accepted — PR0 Approved; persiapan PR1 selesai untuk review 28 November 2026; hasil PR1/DDL aplikasi belum disetujui |
| Branch dan baseline | `Milestone-2` / `4b29461`; working tree bersih saat mulai |
| Requirement | ACAD-001–007 dan course/enrollment ERD v1.1 |

M2 dilaksanakan satu tahap setiap kali, lalu berhenti untuk review. Tujuan akhirnya
adalah admin tiga sekolah sintetis menyiapkan kelas/course tanpa SQL manual.
Pengguna menyetujui seluruh PR0 pada 28 November 2026 melalui chat. Approval mencakup
desain dan izin menyusun PR1, bukan izin apply DDL aplikasi atau mengerjakan semua PR.

## Dokumen dan gate

- [PRD Academic v0.1](../prd/PRD_Modul_Academic_Administration_v0.1.md) Accepted:
  kebutuhan, permission, D01–D06 dan alur UI/CSV.
- [Amendment ERD v0.1](../erd/ERD_M2_Academic_Amendment_v0.1.md) Accepted:
  physical conventions, status, FK/check/index, batch dan dampak existing.
- [M2 Architecture Gate](M2_ARCHITECTURE_GATE.md): keputusan manusia, izin tahap dan
  izin DDL aplikasi terpisah. Baseline Accepted tetap mengikuti [indeks](../DOCUMENT_INDEX.md).

M1 Closed by owner pada 25 November. Commit M1 terbaru `4b29461` membawa isolasi
file test; record 26 November menyebut 104 test/816 assertion lulus, bukan run baru
pada tugas dokumen ini. Bukti browser publik HTTP M1 tersedia; login/MFA/recovery
HTTPS dan readiness produksi belum lengkap. M2 tidak memberi waiver atau izin data nyata.

## Audit repository PR0 pada 27 November 2026

| Area | Temuan repository pada baseline |
|---|---|
| Branch | Milestone-2 aktif dan baseline M1 terbaru sudah di-commit |
| Modul/schema | Belum ada Academic atau migration M2; 5 migration M1/MFA existing |
| Identity | `TenantMemberships` hanya readiness/archive/revocation; eligibility reader dan port invitation/reissue M2 perlu dirinci |
| Tenancy | Registry lifecycle/lock tersedia; edit metadata existing berorientasi platform, belum port profil school admin |
| RBAC | Permission academic/classroom/subject/course sudah di katalog; teacher tidak punya courses.manage, jangan tambah grant administratif agar query guru lolos |
| Boundary tests | Owner map M1 belum memuat 10 tabel Academic dan seluruh forbidden edges baru |
| Test safety | Gunakan operator + launcher storage sementara; guard test PostgreSQL tetap lms_saas_test |
| Runtime grants | Tabel baru perlu penerapan ulang grants dan uji runtime; audit SELECT/INSERT saja |
| Git docs | Baseline desain lama masih local/ignored; hanya proposal M2 baru dan approval dipilih untuk dilacak |
| Runtime hari ini | Docker/database/browser tidak diperiksa ulang oleh audit dokumentasi; jangan menganggap hasil lama sebagai status live |

## Urutan tahap dan izin pelaksanaan

| Tahap | Scope dan file/area | Exit review |
|---|---|---|
| PR0 Approved | PRD Academic, amendment ERD, plan dan gate; indeks/README/changelog/ignore terbatas | Disetujui pengguna 28 November 2026; D01–D06, schema plan dan izin menyusun PR1 tercatat |
| PR1 | Contracts/DTO eligibility Identity dan profil Tenancy, owner/edge architecture guard; schema M2-A di database test setelah approval desain | FK/check/index, up/down test PG, konvensi resource/binding dan physical migration plan direview; apply aplikasi membutuhkan izin DDL tersendiri |
| PR2 | UI profil sekolah, periode, mapel dan profil guru/siswa manual; entry adapters tipis ke pemilik modul | Admin dua tenant dapat CRUD sah; permissions, date/state, revocation dan no credential write lulus |
| PR3 | Schema classrooms/placement, UI wali kelas, placement dan transfer; audit atomik | Cross-tenant FK, satu placement active per tahun, concurrency/rollback dan histori lulus |
| PR4 | Schema course/pengampu/roster, UI dan public read contracts Academic, checklist onboarding agregat central | Same-year FK, eligibility, source, withdraw/re-enroll, minimal primary data exposure dan negative resource tests lulus |
| PR5 | CSV preview/confirm/report/cleanup; port invitation/reissue Identity untuk akun baru; tidak ada tabel impor tambahan | Validasi seluruh baris, atomicity, retry setelah commit/cache failure, wrong tenant/actor/version, kode privat dan missing preview lulus |
| PR6 | Regresi, browser tiga sekolah, UX mobile, runbook operasional dan final review | Exit ACAD-001–007 terbukti dan pemilik memberi approval; tidak otomatis mulai M3 |

Penyusunan PR1 diminta pengguna pada 28 November setelah pencatatan approval PR0.
[Migration plan PR1](M2_PR1_MIGRATION_PLAN.md) merinci persiapan contract/DTO,
architecture guard, sinkronisasi dictionary/diagram dan draf schema M2-A yang hanya
diuji pada database test. Adapter/layanan bisnis dan UI belum dibuat. Apply database
aplikasi tetap menunggu review hasil PR1, daftar migration/preflight dan izin DDL terpisah.
PR2–PR6 belum diizinkan; tiap tahap berhenti untuk review sebelum lanjut.
Setiap tahap menyerahkan
file berubah, hasil tes yang benar-benar dijalankan, blocker/approval, analisis dampak,
dan saran commit; tidak melakukan push/merge tanpa izin.

## Kontrak dan dependency yang perlu dituntaskan

Academic menggunakan port Identity untuk lookup membership same-tenant, status user,
roles dan identitas minimum. Lookup selector dari username/user ULID diselesaikan
server; bukan `users::find()` unscoped pada action Academic. Port batch read dibatasi
ID yang telah diotorisasi untuk resource/actor agar query daftar tidak N+1 atau bocor.
Port undangan/reissue tetap di Identity, tanpa reverse dependency ke Academic.

Profil sekolah ditulis oleh Tenancy dengan target dari context dan allowlist. Academic
menyediakan query course/teacher/enrollment untuk consumer Learning/Assessment, bukan
mengimpor modul masa depan. Summary onboarding central adalah read port terpisah
yang hanya mengembalikan boolean untuk tenant kanonis setelah izin platform diperiksa;
bukan cara membuka query siswa dengan identitas staf pusat.

Mutasi lintas modul membutuhkan satu transaksi dan audit; lock tenant diambil dahulu,
kemudian year/profile/course dengan urutan konsisten dan expected state. Jangan membuat
observer Identity yang menulis tabel Academic untuk menyinkronkan role/status.

## Acceptance dan matrix minimum M2

| ID | Skenario wajib | Expected result |
|---|---|---|
| M2-T01 | Host/tenant_id input palsu; resource B di host A untuk GET/POST/PATCH/DELETE | Fail closed; 404 resource lain; tidak ada side effect bisnis |
| M2-T02 | Platform staff tanpa membership mencoba route akademik; teacher/student mencoba admin CRUD | Ditolak; platform permission bukan permission sekolah |
| M2-T03 | Profile A menunjuk membership B; assignment guru B; course subject B; source siswa lain | Composite FK PostgreSQL menolak |
| M2-T04 | Course semester tahun X/classroom tahun Y | FK komposit same-year menolak |
| M2-T05 | Dua tahun aktif, dua semester aktif per tahun, dua placement aktif secara konkuren | Tepat satu sukses; konflik terkontrol, bukan half-state |
| M2-T06 | Tanggal terbalik/out-of-year/overlap; active semester pada draft year; closed edit | CHECK atau domain validator menolak sesuai amendment |
| M2-T07 | Membership/user suspended, role revoked, profil inactive atau assignment/enrollment dicabut | Sesi/akses efektif ditolak segera; tenant lain tidak ikut berubah |
| M2-T08 | Wali kelas membaca rombel lain; siswa membaca roster orang lain; guru bukan pengampu | Ditolak walaupun role/permission dasar ada |
| M2-T09 | Transfer/enroll/ganti pengampu gagal saat audit atau di tengah write | Rollback; histori dan ID lama tetap; tidak ada dua placement active |
| M2-T10 | Withdraw/re-enroll, source mismatch classroom, pengampu efektif terakhir dicabut | State/eligibility benar, audit perubahan, histori tidak dihapus |
| M2-T11 | CSV invalid/duplicate/oversize/extra column atau account-link terselubung | Preview menolak; 0 write bisnis, tidak auto-link akun global |
| M2-T12 | CSV valid, repeated confirm, confirm konkuren, commit sukses lalu cache gagal | Satu penerapan; marker audit atomik; tidak ada user/profil ganda |
| M2-T13 | Preview/file expired, actor/version berubah, handle tenant lain, failure row terakhir | Fail closed; rollback seluruh batch; tidak ada token/PII pada report/log |
| M2-T14 | New-account invitation/reissue, wrong-host/replay/expiry dan penerima existing global | M1 guard tetap berlaku, kode sekali tampil per penerima, tidak ada password CSV |
| M2-T15 | Runtime bukan owner mencoba DML Academic dan UPDATE/DELETE/TRUNCATE audit | DML yang diperlukan boleh, mutasi audit tetap ditolak |
| M2-T16 | Test CLI selesai/gagal lalu buka web sebagai www-data | Storage test terpisah, cache/log web tetap terbaca/tertulis |
| M2-T17 | Tiga sekolah sintetis menyiapkan seluruh konfigurasi lewat browser | Tidak perlu SQL/tinker; screenshot tersanitasi dan hasil per requirement tercatat |

Per tahap, tambahkan positive case, validation, policy dan cross-tenant tests bersama
fiturnya. Test matrix yang baru relevan pada M3/M4 (submission/grade/attempt) tetap
backlog consumer, bukan diklaim lulus sebelum tabel tersebut dibuat.

## Aturan migration dan operasi

- Tidak ada migration, seed atau reset database aplikasi pada PR0. DDL batch baru
  menunggu izin spesifik; apply bukan efek samping menjalankan setup.
- Tabel Academic additive; jangan edit migration M1 Ran atau migrate:fresh aplikasi.
  Sebelum apply: backup/target/grants dan output runtime operator direview.
- Gunakan operator migration dengan secret privat, bukan kredensial owner di `.env`
  app/worker. Setelah apply ulangi grants lalu runtime permission tests.
- Jangan memperluas Docker M0, package, frontend SPA, public API atau RLS tanpa alasan
  dan persetujuan terpisah. RLS/outbox/produksi tetap gate yang belum selesai.
- Tes database hanya `lms_saas_test` atau PostgreSQL sintetis terisolasi; file test
  memakai launcher privat. Jangan menaruh data yang perlu dipertahankan di DB test.

## Verifikasi tahap dokumen

PR0 memeriksa branch/status, source/schema/permission existing, link lokal, keterlacakan
ACAD/D01–D06, daftar 10 tabel dan aturan Git ignore. Backend regression, Docker, PG,
browser dan CI tidak dijalankan untuk mengesahkan fitur yang belum dibuat.
Hasil pemeriksaan final dicatat pada [gate PR0](M2_ARCHITECTURE_GATE.md).

Saran commit pencatatan approval PR0: `docs: record M2 PR0 approval and synchronize accepted documents`.

## Riwayat versi

| Versi | Tanggal | Perubahan |
|---|---|---|
| 0.1 | 2026-11-27 | Proposal scope, urutan PR0–PR6, kontrak, test matrix dan gate DDL |
| 0.1 — approval | 2026-11-28 | Pengguna menyetujui semua PR0; plan Accepted, persiapan PR1 diizinkan tanpa memulai tahap atau apply DDL aplikasi |
| 0.1 — persiapan PR1 | 2026-11-28 | Pengguna meminta persiapan PR1; migration plan, kontrak/DTO dan schema test disiapkan, tanpa acceptance hasil PR1 atau izin DDL/PR2 |
