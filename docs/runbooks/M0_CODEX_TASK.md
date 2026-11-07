# Prompt Codex — Jalankan dan Verifikasi M0

Baca `AGENTS.md`, `docs/DOCUMENT_INDEX.md`, PRD Master, ADR-001, ADR-002 dan ERD v1.1 sebelum mengubah kode.

Tujuan: selesaikan **hanya Milestone M0** untuk LMS SaaS di Windows Docker Compose. Starter repository berisi skrip `scripts/setup.ps1` yang menurunkan skeleton resmi Laravel 13 saat instalasi pertama. Jangan membangun authentication, tenant, RBAC, atau migrasi ERD v1.1 pada tugas ini.

Tugas:

1. Periksa Docker Compose, image PHP, PostgreSQL, Redis, Mailpit, worker dan scheduler.
2. Jalankan `scripts/setup.ps1` atau perintah manual dari runbook.
3. Verifikasi versi Laravel/PHP dan keberadaan `composer.lock`.
4. Pastikan HTTP `/` dan `/health` merespons.
5. Jalankan semua tes dan Pint; perbaiki error tanpa mengubah keputusan ADR.
6. Pastikan semua folder kode mengikuti `app/Modules` dan tidak ada `app/Domains`.
7. Laporkan file berubah, hasil perintah, log error penting, risiko tersisa dan apakah checklist M0 lulus.
8. Setelah selesai dan disetujui, commit `composer.lock` serta skeleton Laravel; jangan commit `.env`, secrets, data nyata, atau folder vendor.

Aturan: jika ada keputusan arsitektur baru yang penting, tulis proposal ADR/review, jangan membuat asumsi tanpa jejak. Uji koneksi dengan PostgreSQL sungguhan (jangan diam-diam mengganti ke SQLite). Jangan klaim M0 sukses tanpa hasil nyata dari local/CI test.
