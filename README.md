# LMS SaaS Multi-Sekolah — Milestone M0 Starter

**Status:** M1 development baseline; aplikasi belum siap produksi. PR4 disetujui pengguna pada 18 November 2026; PR5 sedang dikerjakan dan hardening production masih terbuka.

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

## 7. Batas keandalan

Artefak ZIP ini telah diperiksa secara statis, tetapi lingkungan penyiapan artefak **tidak memiliki Docker dan tidak dapat mengambil paket Composer**, sehingga proses build/boot belum diuji end-to-end di lingkungan tersebut. Gunakan skrip verifikasi setelah diekstrak; laporkan log jika ada error.

Lihat `docs/runbooks/M0_WINDOWS_SETUP.md` dan `docs/runbooks/M0_ACCEPTANCE_CHECKLIST.md`.
