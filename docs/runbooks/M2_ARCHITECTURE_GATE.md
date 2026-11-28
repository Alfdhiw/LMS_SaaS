# M2 Architecture Gate dan approval PR0–PR1

> Status terkini: PR1 disetujui, workflow/UI PR2 disiapkan untuk review dan belum
> disetujui. PR3 belum diizinkan. Header dan riwayat sebelumnya adalah snapshot.

| Metadata | Nilai |
|---|---|
| Tanggal | 27 November 2026 |
| Versi | 0.1 |
| Approval PR0 | 28 November 2026, oleh pengguna/pemilik proyek melalui chat |
| Status | Approved untuk PR0; PRD, amendment ERD dan plan v0.1 Accepted |
| Baseline | `4b29461`, branch `Milestone-2` |
| Tahap sekarang | PR0 Approved; persiapan PR1 selesai dan siap review; hasil PR1/DDL aplikasi/PR2 belum disetujui |

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
- [x] Izin DDL aplikasi PR1 diberikan terpisah; pelaksanaan tetap bergantung pada preflight aman di bawah.

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

## Persiapan PR1 pada 28 November 2026

Pengguna menyatakan: "lanjutkan persiapan PR1". Baseline kerja `08195f8`, branch
`Milestone-2`, working tree bersih sebelum perubahan. Izin ini melaksanakan
persiapan PR1 yang disetujui pada PR0, bukan approval hasil PR1 atau izin DDL aplikasi.
Catatan "PR1 belum dimulai" di bagian pencatatan PR0 adalah snapshot sebelumnya.

[Migration plan PR1](M2_PR1_MIGRATION_PLAN.md) memuat signature contract, daftar tabel,
file/digest draf, preflight dan batas bukti uji. Kontrak/DTO Identity dan Tenancy
ditambahkan tanpa implementasi/binding/route; architecture guard memuat owner seluruh
10 tabel dan dependency rules Academic. Dictionary Core/diagram diselaraskan dengan
amendment Accepted, tanpa keputusan bisnis baru.

Draf lima tabel M2-A ada di `database/migrations/pending_m2`, tidak ikut discovery
default. Up/down hanya diizinkan pada PostgreSQL `lms_saas_test` dalam environment
testing. Tidak ada migration final, schema/data aplikasi, grant runtime aplikasi,
credential, Docker, permission atau Git commit/push/merge yang diubah.

Hasil verifikasi 28 November 2026:

- Subset PR1/architecture: 43 test/477 assertion lulus. Dua race unique index memakai
  proses independen dan masing-masing hanya satu sukses.
- Regresi penuh pertama: 142 lulus, 2 timeout subprocess M1 (15 detik), 1241 assertion.
  Pengulangan runtime terpisah lulus 4 test/25 assertion tanpa perubahan kode/timeout.
  Regresi penuh kedua kemudian lulus 144 test/1247 assertion, 511,99 detik; hasil run
  pertama tidak dihapus atau disamakan dengan bukti run kedua.
- Pint seluruh project 147 file dan Composer validate lulus; enam smoke HTTP tamu
  lulus. Tidak ada browser/HTTPS acceptance atau CI GitHub baru pada tahap ini.
- Probe read-only aplikasi sebelum/sesudah cocok: schema fingerprint yang sama,
  5 migration, 3 tenant, 4 user dan seluruh delapan jumlah baris yang diukur tetap.
  Runtime tetap lms_runtime dengan audit SELECT/INSERT; permission log/Blade aman
  bagi www-data, tanpa root-owned Blade baru atau direktori test di container app.
- Pemeriksaan statis tautan file, entitas diagram, digest draf, ignore guard, manifest
  perubahan dan `git diff --check` lulus. Mermaid belum dirender; detail dan daftar
  file lengkap ada pada migration plan PR1. Core/AGENTS lokal tetap di-ignore.

Error sintaks test immutability pada run awal sudah diperbaiki sebelum subset lulus.
Tidak ada blocker implementasi pada scope persiapan; approval hasil PR1, preflight
DDL dan implementasi adapter/business policy PR2 tetap merupakan gate terbuka.

- [x] Hasil persiapan PR1, contract/DTO dan migration plan disetujui pengguna.
- [x] Finalisasi/apply batch M2-A pada database aplikasi diizinkan setelah preflight.
- [x] Diizinkan lanjut PR2 setelah review PR1.

## Approval PR1 dan izin PR2 pada 28 November 2026

Pengguna/pemilik proyek menyatakan melalui chat: "oke saya setuju untuk PR1 dan
jalankan migration pada database sekaligus lakukan implementasikan adapter/policy PR2
dan perluas guard AST". Persetujuan ini mencakup hasil PR1, izin DDL M2-A pada
database aplikasi lokal setelah preflight, dan pekerjaan PR2 yang diminta. Approval
PR2, PR3–PR6, data nyata, deployment, push dan merge tidak diberikan. Hasil backup,
preflight, migration dan PR2 harus dilaporkan sebagai bukti aktual secara terpisah;
approval ini bukan pernyataan bahwa semua langkah tersebut telah berhasil.

## Penerapan M2-A lokal dan pekerjaan PR2 pada 28 November 2026

Sebelum migrasi: target `lms_saas`/owner `lms_user`, lima migration M1 Ran, satu
migration M2-A Pending, tanpa konflik nama tabel; 3 tenant, 4 user, 3 membership.
Compose lokal sehat dan guard runtime/audit lulus. Backup custom-format dibuat dengan
mode 0600, dipulihkan ke database baru `lms_restore_m2_pr1_20261128` yang privat;
17 tabel, 5 migration, 3 tenant, 4 user dan 3 membership teramati pada hasil restore.
Backup disimpan pula sebagai `../.lms-saas-ops/m2-pr1-pre-migration-20261128.dump.dpapi`
dan verifikasi round-trip DPAPI CurrentUser cocok dengan file dump asli. File container
`/tmp/lms_backup_m2_pr1_20261128.dump` dan database hasil restore tetap dipertahankan.
DPAPI terikat profil Windows pengguna; bukan backup offsite/produksi atau pemulihan
APP_KEY, Redis, file privat dan grants. Jangan membagikan dump lintas tenant.

Migration final `database/migrations/2026_11_28_000100_create_m2_academic_foundation.php`
diuji pada `lms_saas_test` dan diterapkan sebagai batch additive melalui operator
owner existing. Guard membatasi up pada PostgreSQL test atau database/role lokal
yang ditentukan; down hanya pada database test. Grant ulang berhasil. Pemeriksaan
pasca-apply: 6 migration, 3 tenant, 4 user dan 3 membership; runtime memiliki DML
pada kelima tabel, audit INSERT ada sementara UPDATE/DELETE tidak ada. Probe runtime
insert/update/delete subject dalam transaksi yang di-rollback lulus; runtime checker
lulus. Ini bukan bukti perubahan nilai seluruh baris M1 atau uji acceptance browser.

PR2 yang sudah disiapkan mencakup adapter pembaca eligibility Identity, adapter profil
Tenancy, policy domain eligibility profil, workflow internal profil sekolah dan
perluasan guard AST. Policy eligibility bukan otorisasi actor. Workflow profil
memeriksa permission, host dan kedua auth version serta audit dalam transaksi; kontrak
adapter tetap tidak boleh dipakai langsung sebagai pengganti workflow tersebut.
Belum ada route/UI atau
workflow lengkap periode, mapel, profil guru/siswa; jangan klaim PR2 selesai atau siap
untuk digunakan sekolah sebelum exit review PR2, permission dan uji HTTP terpenuhi.
Tidak ada PR3, data nyata, push atau merge.

Hasil verifikasi yang benar-benar dijalankan:

- Sebelum apply: subset migrasi/guard/contracts 44 test, 469 assertion lulus,
  termasuk FK silang tenant dan dua race unik PostgreSQL.
- Regresi pertama setelah apply: 146 lulus, 1 gagal, 1.282 assertion; uji M1
  mengasumsikan total 95 constraint untuk seluruh schema. Test diperbaiki agar
  tetap memeriksa 95 constraint pada tabel M1, bukan menghapus pemeriksaan.
- Subset sesudah perbaikan dan workflow: 18 test, 276 assertion lulus, termasuk
  audit failure rollback, host/version/permission salah dan pembacaan revocation.
- Regresi penuh ulang: 148 test, 1.273 assertion lulus, 213,20 detik.
  Pint 152 file, Composer validate, 6 smoke HTTP tamu, `git diff --check`,
  runtime storage dan audit privilege check lulus. Setiap hak SELECT/INSERT/
  UPDATE/DELETE kelima tabel diperiksa terpisah. Probe DML rollback hanya
  dilakukan pada tabel `subjects`; tidak ada uji browser login/HTTPS atau CI baru.
- Guard AST tetap statis dan tidak membuktikan seluruh SQL/fungsi dinamis, alias
  model arbitrer atau akses runtime. Panggilan `SchoolProfiles::update` dikecualikan
  secara sempit untuk property constructor Identity yang bertipe kontrak tersebut;
  pengujian juga memastikan receiver tidak bertipe kontrak tetap ditolak.

Catatan historis persiapan PR1: ketiga butir berbeda dari approval PR0 dan saat itu
belum disetujui. Approval berikutnya dicatat pada bagian "Approval PR1 dan izin PR2".
Fixture tetap sintetis; evidence login/HTTPS M1 dan gate produksi tidak diberi waiver.

## Implementasi workflow dan UI PR2 untuk review

Pengguna meminta melanjutkan penyelesaian workflow dan UI PR2. Halaman sekolah yang
tersedia adalah `/school/profile`, `/academic/periods`, `/academic/subjects`, dan
`/academic/profiles/student` atau `/academic/profiles/teacher`, melalui host sekolah.
Permission tetap `school.view/update`, `academic.manage`, `subjects.manage`, dan
`members.view` sesuai aksi. Host terverifikasi dan sesi dengan kedua auth version
digunakan; mutasi akademik mengunci tenant, memeriksa ulang otorisasi dan mencatat
audit dalam transaksi. Tidak ada update credential atau perubahan membership oleh Academic.

Draft tahun yang belum memiliki semester/rombel dapat diedit; tahun dan semester
closed tidak dapat diedit atau dibuka kembali. Bentuk perubahan ini sengaja konservatif
untuk menjaga histori. Profil berasal dari username membership sekolah yang sama,
dibuat inactive dan hanya dapat diaktifkan dengan user, membership dan role yang sesuai.
UI menampilkan status dan eligibility terpisah. Mapel dapat dinonaktifkan tanpa hapus.

Regresi penuh awal untuk implementasi PR2 lulus 153 test/1.341 assertion sebelum
penguatan pemeriksaan resource scoped sebelum validasi payload. Uji HTTP awal yang
dijalankan ketika controller dan template sedang berubah gagal akibat variabel view;
pengulangan pada kode selaras lulus. Browser bawaan gagal terhubung karena metadata
sandbox tidak tersedia; uji visual/login dan CI GitHub belum dibuktikan. Uji terarah
setelah penguatan resource scoped lulus 11 test/217 assertion, mencakup payload tidak
valid pada ULID tenant lain, CSRF, audit rollback, revocation dan dua admin sekolah.
Data tes sintetis saja;
tidak ada migration, perubahan Docker, seed aplikasi, commit, push, merge, atau PR3.

- [ ] Hasil PR2 direview dan disetujui pengguna.
- [ ] Pengguna mengizinkan lanjut PR3.
