# Milestone M0 — Definition of Done

> Isi checkbox **setelah diuji di komputer pengembang**. Dokumen starter saja tidak cukup untuk menandai M0 selesai.

- [ ] Docker Desktop dan WSL2 berjalan.
- [ ] `docker compose config --quiet` berhasil.
- [ ] Docker app image PHP 8.4 dibangun tanpa error.
- [ ] Skeleton resmi Laravel 13 + `composer.lock` berhasil terpasang di root repository.
- [ ] `docker compose ps`: web, app, db, redis, worker, scheduler, mailpit hidup; DB dan Redis sehat.
- [ ] `http://localhost:8080` memuat halaman M0; `/health` mengembalikan `{"status":"ok"}`.
- [ ] `docker compose exec -T app php artisan migrate --force` lulus; tidak ada tabel akademik/tenant yang dibuat.
- [ ] `docker compose exec -T app php artisan test --filter=M0` lulus (termasuk koneksi PostgreSQL dan Redis).
- [ ] `docker compose exec -T app php artisan test` lulus seluruh suite.
- [ ] `docker compose exec -T app ./vendor/bin/pint --test` lulus.
- [ ] `.env` tidak masuk Git; `composer.lock` masuk Git.
- [ ] `AGENTS.md`, `docs/DOCUMENT_INDEX.md`, PRD/ADR/ERD lengkap dan selaras.
- [ ] GitHub Actions M0 CI hijau setelah repository dipush.
- [ ] Tidak ada migrasi tenant, RBAC, atau billing di luar scope M0.

## Blocker M1 yang tetap terbuka

1. Persetujuan ADR-001 dan ADR-002 (saat ini Proposed).
2. Persetujuan ERD v1.1 (saat ini Proposed).
3. Pilihan library tenancy/RBAC, strategi session dan security acceptance tests.
4. Implementasi host resolution, auth, provisioning, session revocation dan negative tests lintas tenant.

Milestone M0 membuat **fondasi**, bukan membuktikan keamanan SaaS multi-sekolah.
