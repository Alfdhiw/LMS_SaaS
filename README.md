# LMS SaaS Multi-Sekolah — Milestone M0 Starter

**Status:** M1 development baseline; aplikasi belum siap produksi. PR5 disetujui pengguna pada 21 November 2026; implementasi PR6 tersedia untuk review, belum disetujui, dan gate production masih terbuka.

## 1. Apa yang tersedia

- Docker Compose: PHP 8.4 FPM, Nginx, PostgreSQL 17, Redis, queue worker, scheduler, Mailpit; Vite hanya dengan profile `assets`.
- Skrip bootstrap yang mengambil **skeleton resmi `laravel/laravel:^13.0`** dari Packagist saat pertama kali setup, menginstal dependency, membuat `composer.lock` dan `APP_KEY` lokal.
- Struktur modul `app/Modules/{Tenancy,Identity,Platform}` untuk implementasi M1.
- Landing page dan endpoint `/health` untuk tes, PHPUnit smoke tests yang memeriksa PostgreSQL/Redis, GitHub Actions CI.
- Central tenant directory/provisioning/lifecycle, invitation email + resend, activation tenant-bound, serta test concurrency/host smoke M1.
- PRD Master, 2 PRD modul, ADR-001/002, ERD v1.1, `AGENTS.md`, dan `docs/DOCUMENT_INDEX.md`.
- Contoh data tiga sekolah **sintetis** dalam JSON (belum dimasukkan ke database).

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

# Semua tes
docker compose exec -T app php artisan test

# Pemeriksaan khusus M0
docker compose exec -T app php artisan test --filter=M0

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

Test otomatis dapat menghapus tabel pada **database test**. `tests/TestCase.php` mengunci dan memverifikasi nama database PostgreSQL `lms_saas_test` sebelum trait migrasi berjalan. Jangan mengubah atau melewati pengaman ini; jangan menyimpan data yang perlu dipertahankan di database test.

Uji logika JavaScript form recovery secara terisolasi menggunakan Node.js: `node --test tests/Support/school_recovery_ui.test.js`. Uji ini tidak menggantikan pemeriksaan browser HTTPS.

Proof of concept hak audit PostgreSQL dapat dijalankan pada `lms_saas_test` saja:

```powershell
Get-Content -Raw -Encoding UTF8 tests/Support/m1_audit_privilege_probe.sql | docker compose exec -T db psql -X -v ON_ERROR_STOP=1 -U lms_user -d lms_saas_test
```

Script memakai role dan data sintetis dalam transaksi yang di-rollback; memerlukan operator test yang dapat membuat role
dan akan menolak database lain. Ini hanya menguji rancangan hak, **bukan** bukti bahwa role aplikasi saat ini sudah
terlindungi. Usulan pemisahan role dan status review dicatat di `docs/runbooks/M1_ARCHITECTURE_GATE.md`.

Rancangan pemisahan role telah disetujui, tetapi rollout lokal **belum selesai**: aplikasi masih menggunakan role
owner/superuser. Skrip `database/security/m1_audit_runtime_grants.sql` baru diuji melalui transaksi rollback di
database test. Uji ulang menggunakan `./tests/Support/m1_audit_runtime_grants_test.ps1`; jangan menerapkan grant atau
template `docker/compose.audit-local.override.yml` ke lingkungan aktif sampai kredensial operator terpisah, jalur
migration/test, dan langkah rollout/rollback siap serta telah diverifikasi.

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

   ```powershell
   docker compose exec -T db psql -U lms_user -d postgres -c "SELECT datname FROM pg_database WHERE datname IN ('lms_saas','lms_saas_test','lms_saas_restore_drill') ORDER BY datname;"
   ```

   Nama `lms_saas_restore_drill` **sudah digunakan oleh latihan yang dicatat di bawah**; jangan menimpanya.
2. `pg_dump -Fc` menghasilkan snapshot PostgreSQL. Pada latihan ini file sementara unik dibuat di `/tmp` container
   dengan `umask 077` dan `set -C` untuk menolak benturan, lalu `docker compose cp` menyalinnya ke
   `storage/app/private/backups/` yang diabaikan Git. Pastikan tujuan di Windows belum ada sebelum menyalin, periksa
   `git check-ignore`, bandingkan SHA-256 sumber dan salinan dengan `sha256sum` dan `Get-FileHash`, dan batasi ACL
   Windows ke akun operator, SYSTEM, dan administrator yang berwenang. Dump **tidak terenkripsi**, berisi seluruh
   tenant serta kemungkinan hash credential/token: jangan commit, bagikan ke sekolah, atau unggah ke layanan publik.
3. Salin kembali file Windows ke nama sementara baru di container dan verifikasi SHA-256 kembali. Hanya setelah target
   database baru dipastikan belum ada, buat menggunakan `createdb`; restore menggunakan `pg_restore --exit-on-error
   --no-owner --no-acl` ke nama database latihan tersebut. Opsi ini tidak membuktikan pemulihan owner/grant asli;
   restore produksi memerlukan rancangan dan pengujian hak akses tersendiri. Periksa exit code setiap perintah.
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
