# Runbook M0 — Windows + Docker Compose

## Pendekatan utama

Di PowerShell dari root repo: `./scripts/setup.ps1`.

## Jika tidak dapat menjalankan skrip PowerShell

Semua perintah berikut dieksekusi dari root folder repo:

```powershell
Copy-Item .env.m0.example .env
# Ubah DB_PASSWORD dan port jika perlu pada .env sebelum melanjutkan.
docker compose config --quiet
docker compose build app
docker compose up -d db redis mailpit
docker compose run --rm --no-deps --entrypoint sh app scripts/bootstrap-laravel.sh
docker compose up -d app web worker scheduler
docker compose exec -T app php artisan migrate --force
docker compose exec -T app php artisan test --filter=M0
docker compose ps
```

`migrate` M0 hanya mencatat baseline migration repository. Semua migration bawaan skeleton Laravel (`users`, `cache`, `jobs`) sengaja tidak dipakai agar ERD v1.1 tidak terkunci sebelum review. Belum ada data tenant.

## Troubleshooting

| Masalah | Pemeriksaan / tindakan |
|---|---|
| `docker` tidak dikenal | Instal/aktifkan Docker Desktop, cek `docker version` dan WSL2 integration |
| `no configuration file provided` | Masuk ke root folder hasil ekstraksi yang memiliki `docker-compose.yml` |
| Port 8080 atau 8025 bentrok | Ubah `APP_PORT` atau `MAILPIT_WEB_PORT` pada `.env`, lalu `docker compose up -d` |
| `vendor/autoload.php` hilang | Jalankan `docker compose run --rm --no-deps app composer install` setelah skeleton dibuat |
| `artisan` hilang | Jalankan `docker compose run --rm --no-deps --entrypoint sh app scripts/bootstrap-laravel.sh` |
| `composer create-project` gagal | Periksa koneksi DNS/proxy Docker; hapus folder `.m0-laravel-scaffold` **hanya bila bootstrap gagal** lalu ulangi |
| Redis/DB tidak siap | Periksa `docker compose ps` dan `docker compose logs db redis`; jangan lanjut sebelum healthy |
| DB test tidak ditemukan | Skrip init test DB hanya berjalan saat volume PostgreSQL baru; buat DB test manual dengan `docker compose exec db createdb -U lms_user lms_saas_test` bila perlu |
| Tidak dapat menulis `storage` | Jalankan `docker compose exec -u root app chown -R www-data:www-data storage bootstrap/cache` (Docker Desktop kadang memerlukan penyesuaian permission) |
| Page 502 | Pastikan `app` berjalan (`docker compose logs app`) lalu ulangi refresh setelah PHP-FPM siap |
| PHPunit memakai SQLite | Jalankan dari container app dan periksa `phpunit.xml`, pastikan DB_CONNECTION=pgsql DB_DATABASE=lms_saas_test |

## Keamanan dan isolasi

- Bind web dan Mailpit ke `127.0.0.1`, PostgreSQL/Redis tidak diekspos ke host.
- Secret contoh hanya untuk development; ubah password sebelum dipakai tim atau server publik.
- M0 tidak memiliki authentication atau tenant guard. **Jangan mengekspos situs ini ke internet atau memakai data siswa nyata.**
- Sesi memakai file lokal sementara; pemilihan final backend sesi di M1 harus melalui review.
- Route `/health` saat M0 hanya menyatakan HTTP hidup, bukan bukti otorisasi atau isolation.

## Kapan dianggap selesai

Ikuti `M0_ACCEPTANCE_CHECKLIST.md`; M0 selesai hanya jika seluruh service start, smoke test & formatter lulus, lockfile committed, review dokumentasi, dan CI hijau di lingkungan pengguna.
