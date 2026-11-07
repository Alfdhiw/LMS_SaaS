# LMS SaaS Multi-Sekolah — Milestone M0 Starter

**Status:** M0 development kit; bootstrap Laravel resmi harus dijalankan pada komputer dengan Docker + akses internet. Belum merupakan aplikasi LMS siap produksi. Semua PRD/ADR/ERD masih `Proposed`.

## 1. Apa yang tersedia

- Docker Compose: PHP 8.4 FPM, Nginx, PostgreSQL 17, Redis, queue worker, scheduler, Mailpit; Vite hanya dengan profile `assets`.
- Skrip bootstrap yang mengambil **skeleton resmi `laravel/laravel:^13.0`** dari Packagist saat pertama kali setup, menginstal dependency, membuat `composer.lock` dan `APP_KEY` lokal.
- Struktur modul awal `app/Modules/{Tenancy,Identity,Platform}` tanpa implementasi fitur M1.
- Landing page dan endpoint `/health` untuk tes, PHPUnit smoke tests yang memeriksa PostgreSQL/Redis, GitHub Actions CI.
- PRD Master, 2 PRD modul, ADR-001/002, ERD v1.1, `AGENTS.md`, dan `docs/DOCUMENT_INDEX.md`.
- Contoh data tiga sekolah **sintetis** dalam JSON (belum dimasukkan ke database).

**Tidak ada**: autentikasi, sesi tenant final, migrasi struktur M1, RBAC, billing, Livewire, domain tenant. Keputusan ini sengaja ditunda sesuai ADR-001/002 dan dokumen indeks.

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

Script akan membuat `.env` dari `.env.m0.example` jika belum ada, build PHP image, menjalankan database/Redis/Mailpit, mengambil skeleton Laravel resmi, menjalankan Composer, membuat application key, menjalankan `migrate` **tanpa tabel aplikasi**, lalu smoke tests M0.

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

`AGENTS.md` → `docs/DOCUMENT_INDEX.md` → PRD Master → ADR-001/002 → ERD v1.1 → PRD modul → kode dan tests. Jangan implementasikan M1 sebelum keputusan skema di-accept. `app/Modules` merupakan struktur yang diusulkan dalam ADR-002.

## 7. Batas keandalan

Artefak ZIP ini telah diperiksa secara statis, tetapi lingkungan penyiapan artefak **tidak memiliki Docker dan tidak dapat mengambil paket Composer**, sehingga proses build/boot belum diuji end-to-end di lingkungan tersebut. Gunakan skrip verifikasi setelah diekstrak; laporkan log jika ada error.

Lihat `docs/runbooks/M0_WINDOWS_SETUP.md` dan `docs/runbooks/M0_ACCEPTANCE_CHECKLIST.md`.
