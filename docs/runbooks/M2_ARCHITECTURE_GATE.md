# M2 Architecture Gate dan approval PR0

| Metadata | Nilai |
|---|---|
| Tanggal | 27 November 2026 |
| Versi | 0.1 |
| Approval PR0 | 28 November 2026, oleh pengguna/pemilik proyek melalui chat |
| Status | Approved untuk PR0; PRD, amendment ERD dan plan v0.1 Accepted |
| Baseline | `4b29461`, branch `Milestone-2` |
| Tahap sekarang | PR0 Approved; penyusunan PR1 diizinkan, belum dimulai; DDL aplikasi belum diizinkan |

Pada 28 November 2026 pengguna menyatakan: "saya approval semua untuk PR0 di M2".
Persetujuan ini menerima scope PR0, D01–D06, PRD Academic, amendment ERD dan rencana
M2 v0.1, termasuk izin menyusun PR1. Codex hanya mencatat keputusan manusia tersebut;
pencatatan ini tidak memulai PR1 atau menyetujui DDL aplikasi yang belum direview.

## Dokumen yang disetujui

| Dokumen | Versi | Status |
|---|---|---|
| [PRD Academic](../prd/PRD_Modul_Academic_Administration_v0.1.md) | 0.1 | Accepted, 28 November 2026 |
| [Amendment ERD Academic](../erd/ERD_M2_Academic_Amendment_v0.1.md) | 0.1 | Accepted sebagai pelengkap M2 untuk Core v1.1 |
| [Master plan M2](M2_MASTER_PLAN.md) | 0.1 | Accepted, 28 November 2026; satu PR per review |

Baseline PRD Master/Auth/Central dan ADR-001/002 tetap v1.0 Accepted; Core ERD v1.1
tetap Accepted. Amendment yang disetujui melengkapi rincian M2, bukan mengganti
seluruh Core v1.1. Sinkronisasi dictionary/diagram aktif menjadi pekerjaan PR1
sebelum migration final; bukan bukti schema telah diterapkan.
M1 tetap Closed by owner dengan batas evidence browser/HTTPS yang tercatat.

## Keputusan D01–D06 yang disetujui

| ID | Keputusan | Konsekuensi | Status per 28 November 2026 |
|---|---|---|---|
| D01 | Satu semester aktif per tahun; closed tanpa reopen; close/activate eksplisit | Partial unique baru; validasi overlap, parent status dan locking | Accepted |
| D02 | Profil invited inactive; active setelah eligibility; dua jenis profil bila dua role | Akun aktif tidak otomatis mengaktifkan profil; revocation tetap segera efektif | Accepted |
| D03 | Course draft/active/closed; assignment_role teacher saja; minimal satu pengampu efektif saat active | Tidak ada lead/assistant; closed immutable; Identity revocation tidak diblokir | Accepted |
| D04 | Transfer diawali preview/konfirmasi; roster course asal belum closed di-withdraw; course baru dipilih eksplisit; source placement diisi | Histori/ID dipertahankan; audit re-enroll; source FK tenant+siswa dan domain classroom | Accepted |
| D05 | CSV existing profile + undangan akun baru tanpa email; preview/confirm all-or-nothing; 500 baris/1 MiB/15 menit configurable | Perlu port invitation/reissue Identity; marker audit atomik untuk retry; tidak ada tabel impor/ekspor kode/password | Accepted |
| D06 | Profil dasar name/timezone/accent melalui Tenancy | Tidak mengubah host/slug/status/paket; upload logo ditunda | Accepted |

Konvensi physical amendment (ULID seluruh 10 tabel, tipe/ukuran/nullability/default,
CHECK/FK/index dan rencana additive) disetujui sebagai dasar perincian migration.
Daftar file migration, nama constraint/index final dan preflight tetap direview pada
tahap terkait. Approval desain ini bukan izin DDL aplikasi.

## Approval record manusia

- [x] Hasil PR0 dan scope ACAD-001–007 disetujui.
- [x] Keputusan D01–D06 dan PRD v0.1 disetujui tanpa revisi pada approval ini.
- [x] Amendment ERD v0.1 dan rencana additive disetujui sebagai dasar perincian migration.
- [x] Diizinkan menyusun PR1, contract/architecture tests dan migration plan; tanpa apply migration pada database aplikasi.
- [ ] Izin DDL aplikasi PR1 diberikan terpisah setelah daftar migration dan preflight direview.

Reviewer: pengguna/pemilik proyek melalui chat. Tanggal keputusan: 28 November 2026
(Asia/Jakarta). Dokumen yang diterima: v0.1 yang disusun 27 November 2026 pada
baseline `4b29461`, branch `Milestone-2`; perubahan status tidak mengubah desain.

Bukti keputusan pengguna:

> saya approval semua untuk PR0 di M2

"Semua untuk PR0" mencakup empat butir PR0 di atas, bukan pemenuhan prasyarat
review DDL PR1 yang belum tersedia. Checklist DDL tetap terbuka. PR2–PR6 tetap
memerlukan izin per tahap; tidak ada izin data nyata, trust CA, deployment, Git
commit/push/merge atau M3. Pekerjaan pada turn approval ini hanya sinkronisasi dokumen.

## Hasil pemeriksaan persiapan PR0 pada 27 November 2026

- Branch `Milestone-2`, baseline `4b29461`; working tree bersih sebelum dokumen dibuat.
- Repository belum memiliki source Academic/migration M2. Dokumen proposal disusun
  dari enam baseline Accepted dan kontrak/permission/source existing.
- Backend regression, PostgreSQL, Docker, browser dan CI tidak dijalankan pada tahap
  dokumen ini. Hasil M1 104 test/816 assertion pada 26 November adalah bukti historis,
  bukan pengulangan atau penerimaan fitur M2.
- Pemeriksaan statis lulus: 36 tautan file lokal tersedia (anchor belum divalidasi),
  7 requirement ACAD, 6 keputusan lintas PRD/ERD/gate, 10 tabel dictionary dan 17
  skenario uji terpetakan. Ini pemeriksaan cakupan dokumen, bukan test implementasi.
- Saat persiapan, empat proposal berstatus Proposed dan dapat dilacak Git; checkbox
  approval belum dicentang. Enam baseline Accepted serta guard `.env`, override,
  `AGENTS.md` dan path backup privat tetap di-ignore. `git diff --check` lulus.

## Pencatatan approval pada 28 November 2026

Status PRD/amendment/plan, indeks, README, changelog dan petunjuk AGENTS lokal
diselaraskan dengan keputusan manusia di atas. PR1 belum dimulai; tidak ada
perubahan kode, migration, database, Docker atau permission. Hasil uji M1 dan
pemeriksaan persiapan PR0 di atas tetap merupakan bukti bertanggal, bukan run baru.

Pemeriksaan statis saat pencatatan lulus: status/version empat dokumen konsisten,
enam keputusan Accepted, kutipan/tanggal pengguna tersedia, empat approval PR0
dicentang dan satu gate DDL tetap terbuka. Sebanyak 40 tautan file lokal tersedia
(anchor belum divalidasi); cakupan 7 requirement, 10 tabel dan 17 skenario rencana
tetap ada. Ignore guard, pemeriksaan whitespace/conflict dan `git diff --check`
lulus. Backend regression, Docker/PostgreSQL, browser dan CI tidak dijalankan untuk
perubahan dokumentasi ini.

## Dampak lintas project dan batas follow-up

Implementasi berikutnya memerlukan owner map/forbidden edges Academic pada architecture
tests, port Identity/Tenancy yang lebih tepat, tenant-safe binding, audit atomik,
grants runtime pada tabel baru, dan reuse storage test privat. PR0 tidak mengubahnya.

Baseline desain lama tetap di-ignore sesuai aturan existing; dokumen/gate M2 dipilih
secara sempit untuk Git. Review GitHub/clone lain belum membawa seluruh baseline
Accepted yang ditautkan. Menentukan baseline sanitized mana yang boleh dilacak adalah
follow-up pemilik, bukan membuka seluruh `docs/` atau secret secara otomatis.
