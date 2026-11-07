# AGENTS.md — LMS SaaS Multi-Sekolah

> **Tujuan:** aturan kerja AI Codex dan kontributor repository. Ini **bukan** pengganti PRD/ADR/ERD, dan bukan bukti bahwa modul sudah terimplementasi. Bahasa dokumen: Indonesia; nama kelas, tabel, variabel, commit, dan identifier teknis dapat memakai bahasa Inggris.

## 0. WAJIB dibaca sebelum tugas apa pun

1. Baca `docs/DOCUMENT_INDEX.md` untuk menemukan **versi aktif dan status persetujuan** dokumen. Jangan mengandalkan filename dalam prompt lama.
2. Baca dokumen berikut dalam urutan ini **jika tersedia di repository**:
   - `docs/prd/PRD_Master_LMS_SaaS_Multi_Sekolah_v1.0.md`
   - `docs/adr/ADR_001_Multi_Tenancy_Identity_Architecture_v1.0.md`
   - `docs/adr/ADR_002_Application_Architecture_Modular_Structure_v1.0.md`
   - `docs/erd/ERD_Core_LMS_SaaS_v1.1.md`
   - PRD modul yang relevan dari indeks (Auth/RBAC atau SaaS Central, kemudian lainnya).
3. Selalu baca file/kode/tests yang sudah ada untuk scope yang dikerjakan. Instruksi ini berlaku **setiap tugas**, bukan hanya saat pertama membuka repository.
4. Jika dokumen hilang, konflik, statusnya *Proposed* tetapi pekerjaan memerlukan keputusan *Accepted*, atau keputusan open memengaruhi keamanan/data: **berhenti pada bagian yang terblokir**, jelaskan konflik dan usulkan perubahan dokumen. Boleh menyelesaikan bagian M0 yang tidak terblokir.
5. Jangan mengubah metadata `Proposed` menjadi `Accepted` secara otomatis. Approval adalah keputusan pemilik produk/technical reviewer, bukan keputusan Codex.

## 1. Source of truth dan aturan konflik

- `docs/DOCUMENT_INDEX.md` menunjuk versi yang berlaku serta statusnya; indeks **tidak mengganti isi** dokumen.
- **PRD** = kebutuhan, fitur, peran, scope milestone, acceptance criteria.
- **ADR** = pilihan dan batas arsitektur; perubahan harus ADR baru/revisi dengan alasan.
- **ERD** = nama tabel, kolom, FK, unique/check/index, klasifikasi data dan migrasi.
- **Kode/test yang sudah ada** = kenyataan implementasi yang perlu diperiksa, **tidak boleh diam-diam mengalahkan dokumen**; laporkan drift.
- Bila dokumen bertentangan, jangan memilih versi yang paling nyaman. Buat catatan konflik dan minta persetujuan perubahan sebelum mengedit bagian berisiko.
- Semua referensi ERD v1.0 pada dokumen lama diperlakukan sebagai historis; **v1.1 adalah revisi terbaru yang diusulkan**, dan baru otoritatif untuk migration setelah disetujui dalam indeks.
- Contoh `app/Domains` pada PRD Master dan `app/Modules` pada PRD modul lama disatukan oleh ADR-002: **struktur yang dipilih adalah `app/Modules`**, setelah ADR-002 di-accept.

## 2. Arsitektur yang tidak boleh dilanggar

- Satu Laravel app, **modular monolith**, shared PostgreSQL database dan Redis; web via Blade/Livewire/Alpine/Tailwind.
- Semua kode bisnis baru ditempatkan di `app/Modules/{Tenancy,Identity,Platform,Academic,Learning,Assessment,...}`. Infrastruktur lintas modul di `app/Support`.
- Jangan membuat `app/Domains` paralel, microservice, schema database per sekolah, public API, atau frontend SPA terpisah tanpa ADR baru.
- Satu tenant = satu sekolah. `users` global; `tenant_memberships` per sekolah; roles/permissions tenant ditautkan ke membership; platform staff **terpisah** dari roles sekolah.
- Port lintas modul berupa `Contracts`, action/queries yang disetujui, dan events. **Tidak boleh direct write** ke model/tabel milik modul lain dari action consumer.
- `Platform` boleh mengorkestrasi `Tenancy` dan `Identity`; `Tenancy` tidak boleh mengimpor `Identity/Platform`; `Identity` tidak boleh mengimpor `Platform`. Ikuti peta dependency ADR-002.
- Jangan mengubah versi Laravel/PHP atau memasang paket tenancy/RBAC tanpa compatibility spike dan dokumentasi keputusan.

## 3. Kontrak keamanan tenant wajib

- Tenant hanya ditentukan dari **hostname tepercaya** yang cocok dengan `tenant_domains`; unknown host **fail closed**. Host central berbeda dari host sekolah.
- `tenant_id` dari request, Livewire public property, header arbitrer, query parameter, atau route parameter **bukan sumber kebenaran**.
- Semua read/write tenant memerlukan `TenantContext` server-verified, membership aktif, status tenant yang diizinkan, role/policy serta resource ownership/enrollment.
- Host-only session, bukan shared login antarsubdomain. Cek `users.auth_version` dan `tenant_memberships.auth_version` untuk revocation.
- User platform tidak otomatis boleh membaca jawaban, materi privat, berkas, atau nilai siswa; jangan membuat `Gate::before` / `Policy::before` sebagai super-admin universal.
- Semua tabel `tenant_owned`: `tenant_id`, FK komposit ke parent tenant, index, policies, dan negative cross-tenant tests. Ikuti ERD; tanpa migration tidak sah.
- Jangan gunakan unscoped Eloquent/raw SQL/global scope bypass pada jalur request; **route model binding harus tenant-safe sebelum query terjadi**.
- Job/scheduler harus mendeklarasikan dan memvalidasi tenant, lalu **reset context di `finally`**. Cache/file/export/signed URL harus tenant-aware.
- Semua file privat secara default; jangan menaruh tugas, jawaban, atau berkas siswa di folder public.
- Jangan seed/log/prompt/screenshot data murid nyata, password, token reset, token undangan, NISN, atau jawaban ujian utuh.
- Aktivasi undangan dan pemulihan password harus single-use, TTL, audit, dan atomic. Admin sekolah tidak boleh reset akun global multi-school.

## 4. Workflow wajib untuk setiap task Codex

### Sebelum mengubah kode

1. Tentukan `milestone`, requirement ID, pemilik modul, dan *scope* yang diminta pengguna.
2. Baca indeks + PRD/ADR/ERD relevan + source yang ada.
3. Sampaikan rencana singkat: file yang terpengaruh, data/migration, dependency, risiko tenant, dan test.
4. Jika ada schema baru/perubahan relasi, **perbarui ERD dan mintalah approval sebelum migration final**. Jangan mengarang nama tabel/kolom sendiri.
5. Tidak boleh memperluas scope diam-diam ke billing, custom domain, SSO, parental portal, atau fitur lain yang belum masuk milestone.

### Saat mengubah kode

- Ikuti nama tabel dan status resmi dari ERD (`tenants`, `tenant_memberships`, `account_invitations`, `tenant_provisioning_requests`, dst.).
- Gunakan FormRequest/validasi input, Gates/Policies untuk aksi, Actions untuk aturan bisnis & transaksi, Queries untuk pembacaan scoped, DTO/Contracts untuk integrasi lintas modul.
- Semua mutasi kritis (provisioning, activation, grading, final exam) harus mendefinisikan transaksi, concurrency, idempotency, audit, dan failure handling.
- Dispatch efek samping eksternal setelah commit. Jangan mengirim email sebagai bagian dari transaksi yang berpotensi rollback.
- Sertakan test positif + test negatif akses lintas tenant untuk setiap resource tenant baru.
- Migrations PostgreSQL mengikuti ERD composite FK, partial unique indexes, check constraints dan kebijakan restrict untuk sejarah akademik.
- Gunakan secrets `.env`, tidak commit `.env` asli, kunci API, DB dump atau data pribadi.

### Sebelum menyerahkan hasil

Jalankan perintah yang memang tersedia dan relevan di repo; jangan mengarang hasil. Minimal untuk perubahan besar:

```bash
composer validate
php artisan test
# Jika dikonfigurasi: vendor/bin/pint --test
# Periksa migration & constraints di PostgreSQL test DB (bukan SQLite saja).
```

Untuk pekerjaan pada tenancy, identity, provisioning, atau perubahan database: tes cross-tenant adalah **wajib**. Bila service Docker/test DB tidak ada, laporkan persis tes yang tidak dijalankan, jangan tulis “pass”.

### Format jawaban akhir Codex

```text
Scope / requirement:
Dokumen yang dibaca (versi + status):
Ringkasan perubahan:
File dibuat/diubah:
Dampak schema / migration:
Dampak tenancy / auth / permission:
Tests dijalankan (perintah dan hasil):
Tests belum dijalankan (alasan):
Risiko, trade-off, dan approval terbuka:
```

## 5. Milestone dan batas pekerjaan

- **M0**: setup Laravel + Docker Compose + PostgreSQL + Redis + dev tooling, fixture sintetis, CI dasar, dokumentasi. Boleh dilakukan sebelum keputusan M1 di-accept asal tidak mengunci schema.
- **M1**: SaaS Central, Tenancy, Auth/RBAC, invitations/recovery, provisioning, role/session/audit dan security tests; **blokir migration final** jika ADR/ERD terkait belum Accepted.
- **M2**: akademik dan course/enrollment.
- **M3**: materi, file, tugas, submissions dan grading tugas.
- **M4**: quizzes/exams/attempts/answers/grades.
- **M5**: paket, subscription manual, entitlement, monitoring dan pilot operasional.
- **M6**: billing otomatis, custom domain dan ekspansi **hanya sesudah PRD/ADR disetujui**.

**Tidak dilakukan pada MVP tanpa keputusan baru:** sekolah self-signup publik, reset akun global multi-sekolah oleh admin sekolah, super-admin impersonation, SSO, shared cookies lintas host, public student files, pembayaran otomatis.

## 6. Aturan menjaga dokumentasi

- Tambahkan/ubah kebutuhan: PRD modul terlebih dahulu.
- Ubah arsitektur atau boundary: ADR baru atau revisi dengan alasan, alternatif, dampak, status.
- Ubah schema: ERD terbaru + konsekuensi data existing + migration/backfill plan + test.
- Update `docs/DOCUMENT_INDEX.md` **setelah** ada keputusan review tentang versi/status; jangan otomatis menghapus dokumen historis.
- Catat perubahan di changelog dokumen dan tautan di PR; dokumentasi & kode harus tetap sinkron.
- Dokumen dalam paket ini mencerminkan desain, **bukan** bukti environment atau implementasi berjalan.

## 7. Prioritas keselamatan data

Jika tugas meminta bypass tenant, menonaktifkan policy, menghapus audit penting, menaruh data siswa di folder publik, atau mengubah migration yang bisa merusak data: **jangan lakukan langsung**. Jelaskan risiko, tawarkan alternatif aman dan minta keputusan eksplisit bila scope berubah.
