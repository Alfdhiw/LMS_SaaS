# LMS SaaS Multi-Sekolah — M1 (lokal/sintetis)

**Status terkini M2, 28 November 2026:** PR1 disetujui; lima tabel M2-A diterapkan
di database lokal setelah backup dan restore test. Workflow dan UI PR2 untuk profil
sekolah, periode, mata pelajaran, serta profil guru/siswa telah disiapkan untuk review;
PR2 belum disetujui. Rincian dan
bukti: [gate M2](docs/runbooks/M2_ARCHITECTURE_GATE.md). Status terdahulu di bawah
adalah snapshot historis, bukan status terkini. Belum ada izin PR3 atau data nyata.

Admin sekolah dapat masuk melalui host sekolahnya, lalu membuka dashboard untuk menuju
Profil Sekolah, Tahun Ajaran dan Semester, Mata Pelajaran, Profil Guru, dan Profil Siswa.
Profil baru dibuat tidak aktif dari membership dengan role yang sesuai; aktivasi profil
memerlukan akun/membership aktif. Data yang digunakan dalam pengembangan harus sintetis.

**Status 25 November 2026:** PR6 **Approved** dan M1 **Closed by owner** berdasarkan approval akhir pengguna, untuk pengembangan lokal dengan data sintetis. Commit remediasi: `015e792`; CI commit tersebut sukses. Bukti browser HTTP/tamu kini tersedia (11 pemeriksaan, §10 laporan), tetapi demonstrasi login/MFA/recovery melalui HTTPS belum lengkap; aplikasi belum siap produksi atau data siswa nyata. M2 belum dimulai/diizinkan. Lihat [approval dan catatan penutupan](docs/runbooks/M1_FINAL_REVIEW.md#9-approval-akhir-pr6m1--25-november-2026) serta [acceptance checklist](docs/runbooks/M1_ACCEPTANCE_CHECKLIST.md).

**Pembaruan 28 November 2026:** PR0 M2 disetujui pengguna pada branch `Milestone-2`, baseline `4b29461`
(perbaikan isolasi file test M1). [PRD Academic](docs/prd/PRD_Modul_Academic_Administration_v0.1.md),
[amendment ERD](docs/erd/ERD_M2_Academic_Amendment_v0.1.md), dan [master plan](docs/runbooks/M2_MASTER_PLAN.md)
v0.1 berstatus Accepted, termasuk D01–D06 dan izin penyusunan PR1.
Bukti keputusan dan izin DDL yang terpisah ada di [gate M2](docs/runbooks/M2_ARCHITECTURE_GATE.md).
Belum ada fitur/UI Academic atau schema M2 yang diterapkan pada database aplikasi. DDL database aplikasi
menunggu review migration plan/preflight PR1; PR2–PR6 belum diizinkan. Status M1/produksi tidak berubah.

**Persiapan PR1, 28 November 2026:** diminta pengguna pada baseline `08195f8`.
Persiapan selesai untuk review: kontrak/DTO dan architecture guard; draf lima tabel Academic diuji hanya
pada `lms_saas_test` melalui path eksplisit dan guard testing. Kontrak belum mempunyai
adapter/binding/route baru. [Migration plan PR1](docs/runbooks/M2_PR1_MIGRATION_PLAN.md)
memuat batas implementasi, evidence dan preflight; hasil PR1/DDL aplikasi masih menunggu review.
Regresi penuh ulang lulus 144 test/1247 assertion, Pint 147 file, Composer validate
dan enam HTTP guest smoke lulus. Dua timeout M1 pada run pertama tetap dicatat di
plan; pengulangan tanpa perubahan kode/timeout lulus. Fingerprint schema dan jumlah
baris aplikasi sebelum/sesudah pengujian tetap sama.

## 1. Apa yang tersedia

- Docker Compose: PHP 8.4 FPM, Nginx, PostgreSQL 17, Redis, queue worker, scheduler, Mailpit; Vite hanya dengan profile `assets`.
- Skrip bootstrap yang mengambil **skeleton resmi `laravel/laravel:^13.0`** dari Packagist saat pertama kali setup, menginstal dependency, membuat `composer.lock` dan `APP_KEY` lokal.
- Struktur modul `app/Modules/{Tenancy,Identity,Platform}` untuk implementasi M1.
- Landing page dan endpoint `/health` untuk tes, PHPUnit smoke tests yang memeriksa PostgreSQL/Redis, GitHub Actions CI.
- Central tenant directory/provisioning/lifecycle, invitation email + resend, activation tenant-bound, serta test concurrency/host smoke M1.
- PRD Master, 3 PRD modul Accepted (Auth/RBAC, SaaS Central dan Academic), ADR-001/002, ERD Core v1.1
  beserta amendment Academic v0.1, `AGENTS.md`, dan `docs/DOCUMENT_INDEX.md`; rencana M2 Accepted,
  tetapi implementasi dan izin DDL tetap mengikuti gate per tahap.
- Contoh data tiga sekolah **sintetis** dalam JSON. Pada instalasi lokal yang direview 24 November 2026 sudah terdapat tiga sekolah sintetis aktif dan empat akun; ini bukan seeder otomatis atau kondisi semua instalasi.

**Belum ada**: modul akademik M2+, billing, entitlement komersial, UI tenant sekolah yang lengkap, durable provisioning outbox, dan kesiapan production final. PRD/ADR/ERD aktif menjadi acuan; tidak ada data siswa nyata.

## 2. Prasyarat Windows

1. Docker Desktop aktif dengan backend WSL 2 (Linux containers).
2. PowerShell 5.1+ atau PowerShell 7+.
3. Koneksi internet pertama kali untuk image Docker dan paket Composer.
4. Ruang disk memadai. Port lokal `8080` (web) dan `8025` (Mailpit) tidak digunakan aplikasi lain.

> **Tidak membutuhkan PHP/Composer/Node lokal.** Seluruh tool berjalan lewat container. Jangan gunakan Laragon pada port yang sama ketika menguji Docker.

## 3. Mulai dari folder hasil ekstraksi

Buka PowerShell di folder yang memiliki `docker-compose.yml` dan `AGENTS.md`:

```powershell
.\scripts\setup.ps1
```

Script akan membuat `.env` dari `.env.m0.example` jika belum ada, build PHP image, menjalankan database/Redis/Mailpit, mengambil skeleton Laravel resmi bila diperlukan, menjalankan Composer, membuat application key, menjalankan migration dan seed katalog role/permission, lalu smoke tests M0.

Jika kebijakan PowerShell melarang eksekusi skrip, jalankan perintah yang sama secara manual sesuai `docs/runbooks/M0_WINDOWS_SETUP.md` (tidak perlu mengubah kebijakan secara permanen).

**Alamat lokal:**

- Aplikasi: http://localhost:8080
- Health: http://localhost:8080/health
- Mailpit: http://localhost:8025

## 4. Perintah sehari-hari

```powershell
# Status semua service
docker compose ps

# Semua tes pada instalasi lokal dengan pemisahan role audit
.\scripts\m1-operator.ps1 test

# Pemeriksaan khusus M0 pada instalasi lokal dengan pemisahan role audit
.\scripts\m1-operator.ps1 test-m0

# Subset regresi; filter tidak dapat dipakai pada operasi migrate/seed
.\scripts\m1-operator.ps1 test -TestFilter 'M1PasswordChangeTest'

# Formatter (ubah file otomatis)
docker compose exec -T app ./vendor/bin/pint

# Pemeriksaan kualitas menyeluruh
.\scripts\verify.ps1

# Composer tambahan
docker compose exec -T app composer require nama/package

# PHP Artisan
docker compose exec -T app php artisan about

# Log aplikasi
docker compose logs -f app web worker scheduler

# Stop (data PostgreSQL tetap disimpan)
docker compose down
```

**Peringatan:** `docker compose down -v` menghapus seluruh volume database dan Redis lokal. Jangan gunakan jika ada data yang masih diperlukan.

Perintah operator di atas membutuhkan override lokal dan secret owner yang dijelaskan pada bagian hardening audit.
Pada baseline M0/CI yang belum menggunakan pemisahan role, gunakan
`docker compose exec -T app sh scripts/m1-test-runtime.sh`.

### Isolasi file test dan izin runtime lokal

Operator `test`/`test-m0`, setup/verify, dan langkah test CI memakai `scripts/m1-test-runtime.sh` sebelum Laravel boot.
Setiap run membuat storage (log, cache Blade, session/file cache, file sintetis) dan bootstrap cache privat yang unik
di `/tmp` container; database tetap dikunci ke `lms_saas_test`. Proses concurrency mewarisi path tersebut.
Direktori sementara dibersihkan saat test selesai maupun gagal, dan exit code test dipertahankan.
`umask 077` operator tetap berlaku; credential owner tidak dipindahkan ke runtime web atau dicetak.

Jangan menjalankan `php artisan test`/PHPUnit langsung pada bind mount lokal untuk workflow sehari-hari:
file output root dapat membuat PHP-FPM `www-data` gagal membaca cache atau menulis log. Memisahkan database test
saja tidak memisahkan file runtime. Gunakan operator di atas pada instalasi dengan role terpisah; launcher M0/CI
tidak memasok credential owner dan bukan pengganti operator.

Jika menjumpai `Permission denied` pada log atau Blade, periksa ownership file spesifik sebagai `www-data`.
Pemulihan 26 November 2026 membenahi ownership log dan cache Blade existing tanpa menghapus isi log,
tanpa `chmod 777`, tanpa menyentuh `storage/app/private`/backup, dan tanpa rebuild database atau perubahan Docker.
Jangan menerapkan `chown/chmod -R storage` untuk memperbaikinya karena file privat memiliki aturan akses berbeda.

Verifikasi 26 November: operator full suite lulus **104 test/816 assertion** (244,67 detik), probe launcher final
lulus **4 test/25 assertion** termasuk boot Laravel dan kompilasi layout central ke path privat. Pint lulus 135 file,
Composer valid, enam host smoke lulus, dan layout central dapat dirender sebagai `www-data` dengan sesi array.
File log dapat dibuka untuk append tanpa menambah isi; 0 cache Blade milik root tersisa setelah test.
Ini bukan pengujian login browser memakai akun pilot; setup/migration/seed aplikasi dan workflow GitHub tidak dijalankan.

Frontend Vite belum diperlukan pada M0; setelah modul UI disetujui, jalankan `docker compose --profile assets up -d vite`. Sesuaikan aplikasi bila hot reload di Windows perlu konfigurasi HMR.

## 5. Git dan lockfile

Setelah setup awal sukses, **commit `composer.lock` dan seluruh skeleton Laravel** yang terbentuk. Jangan commit `.env`, `vendor/`, `node_modules/`, data siswa asli, atau kunci/API token.

```powershell
git init
git add .
git status
git commit -m "chore(m0): bootstrap Laravel SaaS local foundation"
```

Workflow CI **memerlukan `composer.lock` yang sudah dilacak Git**; CI akan gagal sebelum setup jika file ini belum dikomit. Setelah proyek terpasang, pertimbangkan mem-pin digest image Docker dan versi extension PHP sesuai hasil uji kompatibilitas lokal. README ini bukan izin mendorong kode ke GitHub secara otomatis.

## 6. Arsitektur & aturan Codex

`AGENTS.md` → `docs/DOCUMENT_INDEX.md` → PRD Master → ADR-001/002 → ERD v1.1 → PRD modul → kode dan tests. Keputusan baseline M1 sudah disetujui; perubahan schema atau arsitektur baru tetap mengikuti gate review. `app/Modules` adalah struktur yang dipilih ADR-002.

### SOP undangan anggota dan pemulihan akun M1

1. Admin sekolah masuk pada host sekolahnya dan membuka **Kelola undangan anggota**. Pilih akun baru tanpa email atau akun existing, isi username dan role sekolah.
2. Verifikasi identitas penerima secara terpisah. Serahkan alamat host sekolah, ID, dan kode undangan hanya melalui kanal pribadi yang disepakati sekolah. Kode berlaku 72 jam, ditampilkan sekali, dan tidak boleh disimpan dalam log atau dikirim ke grup.
3. Penerima membuka `/invitations/accept` pada host sekolah tersebut. Untuk akun baru penerima membuat password; untuk akun existing penerima memasukkan email terverifikasi dan password akunnya sendiri. Admin tidak meminta password penerima.
4. Pengguna dengan email terverifikasi dapat membuka **Lupa password** pada host sekolah tempat ia mempunyai membership. Pesan yang tampil sama untuk alamat yang dapat dipulihkan maupun tidak. Token dikirim setelah commit, berlaku 30 menit, dan dikonsumsi sekali. Reset mencabut sesi lama secara server-side pada seluruh host melalui `users.auth_version`.
5. Untuk siswa tanpa email, siswa membuka **Bantuan password siswa tanpa email** pada perangkat/sesi privat dengan HTTPS,
   membuat permintaan dan menunjukkan hanya ID kepada admin. Admin membuka **Verifikasi pemulihan siswa**, memeriksa
   identitas secara tatap muka sesuai SOP sekolah, dan menyetujui permintaan. Siswa membuat password pada sesi pribadinya;
   admin tidak meminta atau melihat token maupun password. Jangan tutup/muat ulang halaman siswa sebelum selesai.
   Rahasia dibuat browser dan tidak disimpan di server; hanya komitmen hash sementara di cache tenant-scoped dengan TTL.
   Cache hilang, permintaan kedaluwarsa, atau sesi privat tertutup mengharuskan siswa memulai ulang.
   Browser harus mendukung Web Crypto dalam secure context (HTTPS); HTTP hostname sekolah lokal tidak mencukupi.
   Recovery ini hanya untuk siswa aktif tanpa email yang memiliki tepat satu membership historis dan bukan staff platform.

Untuk lokal, Mailpit digunakan melalui SMTP; buka `http://localhost:8025` hanya untuk akun sintetis. Sebelum production, gunakan HTTPS dan transport email yang benar, tetapkan trusted proxy dengan benar, dan pastikan secret tidak masuk log. Transport `log`, `failover`, dan `roundrobin` tidak diperbolehkan untuk email recovery. Delivery setelah commit belum memiliki durable outbox; kegagalan dapat memerlukan permintaan ulang.

### Ganti password mandiri

Pengguna yang sudah login dapat membuka **Ganti password** di dashboard sekolah atau portal pusat (`/password/change`).
Masukkan password saat ini dan konfirmasi password baru 12–128 karakter, dengan batas byte mengikuti hasher (bcrypt
maksimal 72 byte; karakter non-ASCII dapat menggunakan beberapa byte). Password tidak boleh setara dengan hash lama.
Central tetap mensyaratkan MFA bila diwajibkan. Endpoint dilindungi CSRF, pembatasan percobaan, dan HTTPS di produksi.
Password baru, kenaikan `users.auth_version`, revokasi recovery aktif, dan audit disimpan dalam satu transaksi.
Setelah berhasil, pengguna keluar dan sesi lama di seluruh sekolah/central ditolak pada request berikutnya.
Admin sekolah tidak memperoleh akses untuk mengganti password global milik orang lain. Akun dengan
`must_change_password` wajib menyelesaikan perubahan sebelum mengakses aksi protected lain; logout tetap tersedia.
Ini tidak mengizinkan penggunaan fallback password sementara atau mereset credential akun pilot yang sudah ada.

### Boundary modul dan job tenant

Platform tetap mengorkestrasi transaksi provisioning/lifecycle/resend, tetapi write Tenancy dan Identity dijalankan
melalui contract/action pemilik. `TenantContext`, hostname utility dan exception operasi bersama berada di Support;
Support tidak mengimpor Modules. Mapping permission/staff pusat dimiliki Platform. Architecture tests menjaga pola
dependency/write M1 tanpa menambah paket atau mengubah ERD.

Job tenant berikutnya harus memakai `App\Support\Tenancy\Jobs\UseTenantContext` dengan locator tenant/host dari server.
Middleware memverifikasi ulang host aktif/terverifikasi dan tenant ID saat eksekusi, kemudian membersihkan context
pada `finally`. Handler tetap wajib memeriksa actor/resource permission; middleware bukan izin akses universal.
Test queue sync Laravel memeriksa serialisasi, job A -> kegagalan -> B, bukan worker Redis lintas proses.

Test otomatis dapat menghapus tabel pada **database test**. `tests/TestCase.php` mengunci dan memverifikasi nama database PostgreSQL `lms_saas_test` sebelum trait migrasi berjalan. Jangan mengubah atau melewati pengaman ini; jangan menyimpan data yang perlu dipertahankan di database test.

Uji logika JavaScript form recovery secara terisolasi menggunakan Node.js: `node --test tests/Support/school_recovery_ui.test.js`. Uji ini tidak menggantikan pemeriksaan browser HTTPS.

Proof of concept hak audit PostgreSQL dapat dijalankan pada `lms_saas_test` saja:

```powershell
Get-Content -Raw -Encoding UTF8 tests/Support/m1_audit_privilege_probe.sql | .\scripts\m1-db-owner-command.ps1 -Mode test
```

Perintah ini memerlukan override role lokal dan secret owner yang sudah dipasang. Script memakai role dan data sintetis dalam transaksi yang di-rollback; memerlukan operator test yang dapat membuat role
dan akan menolak database lain. Ini hanya menguji rancangan hak, **bukan** bukti bahwa role aplikasi saat ini sudah
terlindungi. Usulan pemisahan role dan status review dicatat di `docs/runbooks/M1_ARCHITECTURE_GATE.md`.

Pada lingkungan lokal yang telah dikonfigurasi, app, worker, dan scheduler memakai `lms_runtime`; hanya `SELECT` dan
`INSERT` diberikan pada kedua tabel audit. Kredensial owner berada di direktori privat `.lms-saas-secrets` di luar
mount aplikasi; `.env` hanya berisi kredensial runtime. Override lokal `docker-compose.override.yml` tidak dilacak Git.
Jangan bagikan atau commit `.env`, direktori secret, salinan `.env` lama, maupun backup database.

Untuk lokal Windows yang sudah dikonfigurasi, jalankan migration menggunakan `./scripts/m1-operator.ps1 migrate`,
seed menggunakan `./scripts/m1-operator.ps1 seed`, dan tes menggunakan `./scripts/m1-operator.ps1 test`. Jalur
migration menerapkan ulang grant pada tabel yang ada; tanpa langkah ini tabel baru tidak otomatis dapat diakses runtime.
Penerapan grant dan helper database privat membaca secret owner dari mount privat di container database; password tidak
dikirim sebagai argumen. Pemeriksaan awal provisioning sebelum override aktif memakai client sementara dengan secret
read-only. Secret tetap tersedia bagi administrator Docker/proses berwenang di dalam container, sehingga host dan
akses Docker harus tetap dilindungi. Pada instalasi lokal ini, pengguna menyetujui pemasangan HBA pada 2026-11-23:
ketujuh rule kini menggunakan SCRAM, termasuk socket/loopback dan replikasi lokal. Setup M0/CI atau volume PostgreSQL
baru tidak otomatis mewarisi konfigurasi volume lokal ini; jangan menganggap instalasi baru sudah memiliki hardening yang sama.
Jangan simpan data yang perlu dipertahankan pada database test. Untuk memeriksa koneksi dan hak audit runtime, jalankan
`docker compose exec -T app php scripts/m1-check-runtime.php`; uji grant sintetis pada database test dapat diulang
dengan `./tests/Support/m1_audit_runtime_grants_test.ps1`. Jika web memberi 502 setelah kontainer app dibuat ulang,
jalankan `docker compose restart web` untuk memperbarui alamat upstream, lalu ulangi host smoke.

Uji integrasi aplikasi sebagai role runtime dapat dijalankan di Windows dengan:

```powershell
.\tests\Support\m1-runtime-integration.ps1
```

Skrip membuat jaringan Docker internal dan PostgreSQL sementara berbasis memori; tidak memakai database utama atau
secret lokal. Owner dan role runtime pada server sementara memakai kredensial sintetis. Proyek di-mount read-only,
file `.env` diganti fixture sintetis, dan storage sementara berada di memori. Tes memeriksa penanda isolasi sebelum
migration, lalu menguji login, sesi lintas tenant, provisioning/aktivasi, dan audit melalui koneksi runtime. Server
dan jaringan sementara dibersihkan setelah tes. Tes ini tidak termasuk suite default `php artisan test`; jalankan
perintah di atas secara eksplisit. Tes ini tidak menggantikan uji HTTPS atau membuktikan RLS.

### Bukti browser publik — HTTP saja

Pengujian 25 November menggunakan Chrome terpasang dalam mode headless dengan profil kosong per kasus,
bukan DOM mock. Sebelas pemeriksaan publik/tamu lulus dan screenshot/manifest tersimpan pada
[`docs/evidence/m1-browser/public-http-20261125T081910548Z-5e84915c/manifest.json`](docs/evidence/m1-browser/public-http-20261125T081910548Z-5e84915c/manifest.json).
Detail dan batasnya: §10 [M1_FINAL_REVIEW.md](docs/runbooks/M1_FINAL_REVIEW.md).

Untuk mengulang setelah container LMS yang sudah ada berjalan dan tiga host sintetis tersedia:

```powershell
$env:M1_BROWSER_VERSION = (Get-Item 'C:\Program Files\Google\Chrome\Application\chrome.exe').VersionInfo.ProductVersion
node tests/Support/m1_browser_guest_evidence.mjs
```

Runner hanya melakukan GET publik: login empat host, unknown/loopback, redirect dashboard tamu, dan form recovery.
DNS dipetakan hanya dalam proses browser. Tidak memasukkan credential, mengirim form, mengubah Windows hosts,
memasang sertifikat, menonaktifkan TLS, atau menguji sesi terautentikasi. Hasil **bukan** kelulusan browser/HTTPS
lengkap. Profil sementara dibersihkan; raw DOM/header/cookie tidak disimpan. Hanya PNG publik dan manifest aman
yang dibuka secara spesifik untuk Git. Jangan gunakan screenshot akun nyata sebagai pengganti fixture sintetis.

Setup M0/CI standar belum otomatis membuat role dan secret terpisah pada instalasi baru; jangan menganggap template
override saja cukup untuk instalasi baru atau produksi. Grant DML pada tabel non-audit masih luas, dan owner/superuser
masih dapat mengubah audit. Perubahan ini belum merupakan bukti kesiapan produksi atau RLS; lihat status dan batasannya
di `docs/runbooks/M1_ARCHITECTURE_GATE.md`.

Pada server lokal yang telah direview, `CONNECT` dari `PUBLIC` dicabut untuk `lms_saas_test`,
`lms_saas_restore_drill`, `postgres`, dan `template1`. Runtime tetap dapat mengakses `lms_saas`, tetapi tidak dapat
membuka koneksi baru ke empat database tersebut; owner `lms_user` tetap dapat terhubung. Pembatasan dari `PUBLIC`
berlaku juga untuk role lain, yang memerlukan grant eksplisit atau hak administratif yang sesuai. Izin `TEMP`, ACL
`lms_saas`, dan `template0` tidak diubah. Koneksi yang sudah ada tidak otomatis diputus oleh perubahan ACL.

Pembatasan ini hanya mencakup empat database yang telah disebut; database baru, termasuk target restore baru, perlu
dibuat melalui helper lokal yang ditinjau di bawah. Jangan menganggap database yang dibuat dengan cara lain otomatis
dibatasi. Lihat catatan preflight, verifikasi, dan pemulihan akses di `docs/runbooks/M1_ARCHITECTURE_GATE.md`;
setiap perubahan izin tambahan memerlukan review dampak dan persetujuan pemilik.

Untuk database restore atau tambahan **baru** pada instalasi Windows lokal yang sudah menggunakan role terpisah:

```powershell
# Pratinjau: tidak membuat database
.\scripts\m1-create-private-database.ps1 -DatabaseName lms_restore_contoh_01 -WhatIf

# Setelah nama/tujuan dan operasi disetujui, jalankan lagi tanpa -WhatIf
.\scripts\m1-create-private-database.ps1 -DatabaseName lms_restore_contoh_01
```

Nama wajib unik, huruf kecil/angka/underscore, dan diawali `lms_restore_` atau `lms_aux_`. `-WhatIf` memeriksa target
Docker dan konfigurasi lokal tetapi belum memeriksa apakah nama database sudah ada. Helper menolak target yang
sudah ada dan memverifikasi Docker Desktop lokal, volume, owner serta role runtime. Database dibuat dengan koneksi
tertutup, lalu `CONNECT` dari `PUBLIC` dicabut dan diverifikasi dalam transaksi sebelum koneksi dibuka. Hanya database
baru yang dibuat helper ini yang dicakup; izin `TEMP` tidak diubah. Jika proses gagal, **jangan menghapus atau mencoba
ulang secara buta**: database mungkin sudah dibuat. Kegagalan sebelum commit membuatnya tetap tertutup; jika hasil
commit tidak diketahui, statusnya perlu diperiksa. Periksa `pg_database.datallowconn`, ACL, dan log operator; tentukan
pemulihan setelah review. Jangan edit katalog sistem secara langsung.

Uji helper dengan data sintetis di PostgreSQL sementara tanpa jaringan/volume persisten:
`./tests/Support/m1-private-database-test.ps1`. Uji ini tidak membuat atau me-restore database pengguna.
Job GitHub Actions `private-database-helper` menjalankan uji helper dan lab SCRAM sintetis dengan PowerShell pada
runner Linux. Semua script dan fixture sintetis yang dibutuhkan harus ikut di-commit; hasil GitHub baru tersedia
setelah perubahan di-commit dan di-push oleh pengguna.

Final review awal memverifikasi [run untuk baseline b07ebdf](https://github.com/Alfdhiw/LMS_SaaS/actions/runs/45137773386).
Pada 25 November 2026, [run M0 checks untuk commit remediasi 015e792](https://github.com/Alfdhiw/LMS_SaaS/actions/runs/45356905502)
juga diverifikasi sukses: job `private-database-helper` dan `smoke`, termasuk langkah Pint dan All tests, sukses.
Run selesai 24 November pukul 15:34:43 WIB. Ini bukti commit tersebut, bukan demonstrasi browser/HTTPS atau hasil CI
bagi perubahan dokumentasi approval 25 November yang belum di-commit/push. Tidak ada workflow/push/merge yang dijalankan oleh Codex.

Lab `./tests/Support/m1-scram-synthetic-test.ps1` menguji usulan SCRAM, bootstrap, dump/restore, dan rollback
pada container sementara tanpa jaringan. Kandidat `database/security/m1_pg_hba_scram_local.conf` mempertahankan tujuh
rule server lokal yang direview dan mewajibkan SCRAM juga untuk replikasi socket/loopback. File tersebut sudah dipasang
pada volume lokal yang disetujui; lab sendiri tetap memakai container sementara dan tidak membuktikan seluruh operasi aplikasi;
hasil dan batasannya dicatat dalam `docs/runbooks/M1_ARCHITECTURE_GATE.md`.
Lab juga menguji perintah owner yang sesungguhnya untuk database privat, grant, dan database test menggunakan secret
sintetis, serta backup/restore melalui wrapper PowerShell dengan discovery Docker yang disimulasikan. Setelah penerapan
lokal, 54 pemeriksaan rollout lulus, termasuk koneksi baru, penolakan credential salah/kosong, hak audit, dan enam rute
HTTP. Migration/seed dan backup/restore penuh pada server aktif belum diulang dalam tahap ini.
Backup HBA dan manifest pemulihan disimpan di `../.lms-saas-ops/hba/`, di luar mount aplikasi dan repository ini,
dengan ACL privat. Prosedur `scripts/m1-hba-rollback.sh` memverifikasi checksum backup dan HBA saat ini, mempertahankan
owner/mode PostgreSQL, lalu meminta reload melalui `pg_ctl` tanpa login database. Penggunaannya hanya untuk pemulihan
yang disetujui; snapshot asli memulihkan pula aturan `trust` lama. Manifest mempertahankan catatan persiapan awal;
hasil penerapan tersedia dalam record `rollout-6345cd195a4c4d30a47e5265ae0179f9.json` pada folder snapshot yang sama.
Lihat identitas snapshot dan langkah verifikasi di gate.

### Runbook pilot M1 — belum siap data nyata

Jangan gunakan data siswa nyata atau menandai pilot siap produksi sebelum gate berikut ditinjau dan disetujui.
Verifikasi browser, GitHub Actions, backup/restore, dan risiko produksi harus memiliki bukti aktual; status lulus test
lokal bukan bukti bahwa seluruh gate tersebut selesai.

1. **Persiapan:** pastikan host pusat terdaftar secara eksplisit, tenant ditentukan dari hostname terverifikasi,
   cookie host-only, dan HTTPS/trusted proxy/session backend telah direview. Untuk produksi operator harus memakai MFA;
   ikuti `docs/runbooks/M1_CENTRAL_MFA_RUNBOOK.md`. Jangan menyalin secret atau kode MFA ke log/tiket.
2. **Data & backup:** gunakan hanya akun dan sekolah sintetis untuk latihan. Pastikan backup yang dapat dipulihkan,
   enkripsi, retensi, hak akses, serta uji restore di lingkungan terisolasi telah disetujui sebelum data nyata.
   Jangan menjalankan `migrate:fresh`, `migrate:rollback`, atau `docker compose down -v` pada database/volume
   yang datanya perlu disimpan. Pengujian otomatis hanya boleh memakai database khusus `lms_saas_test`.
3. **Demonstrasi empat host:** dengan operator pusat sintetis yang tersedia, buat tiga sekolah sintetis melalui
   direktori central. Aktivasi admin dan tenant sesuai readiness; jangan memasukkan email/identitas nyata. Pada masing-masing
   host sekolah, verifikasi login tenant; pada central, periksa total/status dan pastikan audit tidak berisi token.
   Jika belum ada operator sintetis, hentikan demo dan siapkan prosedur bootstrap terpisah yang disetujui.
4. **Host smoke lokal:** setelah tiga tenant sintetis aktif, jalankan:

   ```powershell
   .\scripts\m1-host-smoke.ps1 -TenantHosts @('sekolah-a.platform.test','sekolah-b.platform.test','sekolah-c.platform.test')
   ```

   Gunakan nama host yang benar-benar terdaftar. Script memeriksa central, unknown host, loopback, dan hingga tiga
   host tenant; hasil tanpa tiga tenant aktif **bukan** bukti demo empat host lengkap.
5. **Verifikasi & respons:** jalankan test PostgreSQL khusus, Pint, dan uji JavaScript sesuai bagian sebelumnya.
   Tinjau dashboard serta `/audit` dengan permission platform yang benar; pastikan admin sekolah tidak dapat membuka
   central dan host lain tidak mendapat akses. Jangan memasukkan secret ke bukti. Saat ada indikasi kebocoran atau
   gangguan, hentikan akses terdampak sesuai SOP insiden, pertahankan bukti, dan libatkan pemilik sistem; jangan
   menghapus volume sebagai langkah diagnosis.
6. **Gate masih terbuka:** keputusan RLS atau kontrol alternatif dan uji pool/worker, durable outbox/retry, backup
   restore, perlindungan data/retensi, MFA dan prosedur recovery, observability/redaction, serta uji beban/staging.
   Audit viewer hanya menampilkan field aman; penolakan nama field sensitif bukan jaminan mendeteksi secret yang
   disisipkan dalam nilai bebas. Audit append-only tingkat hak database dan retensi harus diputuskan sebelum produksi.

### Runbook latihan backup/restore PostgreSQL lokal

**Batas:** prosedur ini hanya untuk database lokal dengan data sintetis. Restore dilakukan ke database baru di
server PostgreSQL Docker yang sama, **bukan isolasi instance/staging**. Jangan pernah menargetkan `lms_saas` atau
`lms_saas_test` sebagai tujuan restore latihan. Jangan memakai `pg_restore --clean`, `migrate:fresh`, `dropdb`,
atau `docker compose down -v` sebagai bagian latihan. Jangan mengubah `.env` atau menghubungkan aplikasi/worker ke
database hasil restore; salinan lama bisa berisi undangan, sesi, dan status otorisasi yang sudah berubah.

1. Pastikan Docker dan PostgreSQL sehat, verifikasi nama database sumber dengan read-only `psql`, dan konfirmasi
   nama tujuan **belum ada** di `pg_database`. Pilih nama database latihan dan nama file backup/tidak sementara yang
   baru dan unik setiap kali; berhenti bila ada benturan atau target tidak jelas. Misalnya, pemeriksaan database:

   Nama `lms_saas_restore_drill` **sudah digunakan oleh latihan yang dicatat di bawah**; jangan menimpanya.
2. Untuk latihan yang telah disetujui, buat dump sumber `lms_saas` dengan nama baru, misalnya:

   ```powershell
   .\scripts\m1-db-owner-command.ps1 -Mode backup -BackupName lms_backup_contoh_01.dump
   ```

   `pg_dump -Fc` membuat file `/tmp/lms_backup_contoh_01.dump` dalam container database dengan `umask 077` dan
   menolak nama yang sudah ada. Jika gagal, file parsial mungkin tetap ada: periksa dan jangan menimpanya. Dump ini
   dapat disalin dengan `docker compose cp` ke
   `storage/app/private/backups/` yang diabaikan Git. Pastikan tujuan di Windows belum ada sebelum menyalin, periksa
   `git check-ignore`, bandingkan SHA-256 sumber dan salinan dengan `sha256sum` dan `Get-FileHash`, dan batasi ACL
   Windows ke akun operator, SYSTEM, dan administrator yang berwenang. Dump **tidak terenkripsi**, berisi seluruh
   tenant serta kemungkinan hash credential/token: jangan commit, bagikan ke sekolah, atau unggah ke layanan publik.
3. Salin kembali file Windows ke nama sementara baru di container dan verifikasi SHA-256 kembali. Hanya setelah target
   baru dipastikan belum ada dan disetujui, buat dengan `scripts/m1-create-private-database.ps1` seperti di atas;
   **jangan gunakan `createdb` biasa** pada prosedur lokal yang sudah memakai role terpisah. Periksa ACL dan bahwa
   runtime ditolak sebelum restore. Contoh restore setelah nama file dan target diperiksa dan disetujui:

   ```powershell
   .\scripts\m1-db-owner-command.ps1 -Mode restore -BackupName lms_backup_contoh_01.dump -Target lms_restore_contoh_01
   ```

   Wrapper menolak database aplikasi/test, file symlink, target nonprivat, dan target yang memiliki objek pengguna;
   lalu menjalankan `pg_restore --exit-on-error --no-owner --no-acl`. Jangan izinkan proses lain terhubung atau menulis
   ke target selama pemeriksaan dan restore: pemeriksaan kosong bukan lock terhadap koneksi lain. Gunakan hanya dump
   tepercaya; restore dapat menjalankan SQL di dalam dump. Jika gagal, target bisa berisi hasil parsial; jangan ulangi
   atau hapus secara otomatis.
   Opsi ini tidak membuktikan pemulihan owner/grant asli; restore produksi memerlukan rancangan dan pengujian hak akses
   tersendiri. Periksa exit code setiap perintah dan ulangi verifikasi akses sesudah restore.
4. Bandingkan tabel dan jumlah baris, migration, struktur/constraint/index, sequence, serta data yang diperlukan.
   Periksa integritas relasi tenant dan uji negatif lintas tenant **hanya pada hasil restore**, dengan transaksi yang
   tidak meninggalkan perubahan. Bila data sumber berubah selama backup/validasi, perbandingan langsung dapat berbeda;
   tentukan snapshot dan ulangi dengan kondisi terkontrol. Jangan tampilkan credential, token atau data pribadi pada bukti.
5. Catat tanggal, versi, lokasi dan keamanan backup, target, durasi, hasil verifikasi dan perbedaan. Setelah salinan
   tersimpan terverifikasi, hapus hanya file sementara yang dibuat khusus untuk latihan. Jangan hapus database latihan
   atau backup tersimpan tanpa keputusan pemilik. Tentukan kebijakan retensi dan salinan terenkripsi di lokasi terpisah
   sebelum memakai data nyata.

**Bukti latihan 21 November 2026:** PostgreSQL/pg_dump/pg_restore 17.11; backup custom-format 70.027 byte dibuat dari
`lms_saas`, disimpan di `storage/app/private/backups/lms_saas-20261121-213744.dump`, kemudian disalin kembali dan
direstore ke `lms_saas_restore_drill`. SHA-256 file container, Windows, dan salinan balik cocok. Seluruh 17 tabel
memiliki jumlah baris dan fingerprint isi yang cocok; 146 kolom, 43 indeks, 95 nama/jenis/status constraint, dan 12
nilai sequence juga cocok. Output definisi 12 CHECK berbeda dalam penulisan cast PostgreSQL; hasil evaluasi keduanya
cocok untuk daftar nilai valid/tidak valid/NULL yang diuji, bukan bukti ekuivalensi untuk seluruh input. Percobaan
perpindahan role lintas tenant pada hasil restore ditolak oleh FK `m1_member_role_same_tenant`; perubahan di-rollback.
Database utama tidak menjadi target restore, tidak ada migration yang dijalankan, dan dua file sementara container
dihapus setelah verifikasi. Backup Windows dan database latihan tetap ada.

Latihan ini **belum** membuktikan restore aplikasi end-to-end, pemulihan `APP_KEY`/MFA, Redis, file/object storage,
role/grant, point-in-time recovery, enkripsi/offsite, atau target RPO/RTO. Backup ini tidak memulihkan data yang hilang
sebelum tanggal pembuatannya dan tidak cukup untuk menyatakan sistem siap menangani data nyata.

## 7. Batas keandalan

Hasil test hanya berlaku untuk environment dan waktu saat test dijalankan. M1 belum boleh dianggap siap produksi atau
pilot berdata nyata hanya karena Docker lokal dan test otomatis berjalan. Review PR6, uji browser HTTPS, bukti CI,
backup/restore, dan gate keamanan/operasional di runbook pilot tetap diperlukan.

Lihat `docs/runbooks/M0_WINDOWS_SETUP.md` dan `docs/runbooks/M0_ACCEPTANCE_CHECKLIST.md`.
