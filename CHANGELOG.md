# Changelog

## Unreleased — M2 PR1 preparation (28 November 2026)

- Atas permintaan pengguna, siapkan port/DTO eligibility Identity dan school profile Tenancy; validasi,
  batas batch, immutability dan allowlist diuji tanpa adapter/binding/route atau UI baru.
- Perluas architecture guard untuk owner 10 tabel Academic, forbidden edges, public port-only imports
  dan larangan direct write lintas modul. Guard statis tetap bukan pengganti policy/runtime tests.
- Tambah draf M2-A (5 tabel) pada subfolder pending yang tidak di-discover default dan menolak up/down
  di luar environment testing/PostgreSQL lms_saas_test. Uji katalog/FK/check/index, up/down dan dua race
  periode aktif menggunakan proses independen; tidak menjalankan migration aplikasi atau rebuild M1.
- Sinkronkan Core dictionary/diagram dengan amendment PR0 Accepted; tambahkan migration plan/preflight.
  Pengecualian Git hanya plan PR1 dan diagram Academic; Core/AGENTS lokal tetap di-ignore.
- Tidak mengubah Docker, dependency, credential/role, permission, status approval PR0/M1 atau memberi
  acceptance hasil PR1/izin DDL/PR2. Tidak melakukan Git commit/push/merge.
- Subset lulus 43 test/477 assertion. Regresi penuh awal 142 lulus/2 timeout subprocess M1 (15 detik);
  runtime terpisah 4 test/25 assertion dan regresi penuh ulang 144 test/1247 assertion lulus tanpa
  perubahan kode/timeout. Pint 147 file, Composer validate, enam HTTP guest smoke dan runtime probe lulus.
- Schema fingerprint dan delapan jumlah baris aplikasi sebelum/sesudah cocok persis; 0 Blade root-owned/
  tidak terbaca, log writable sebagai www-data. Static docs/digest/manifest/ignore guard dan diff check lulus;
  tidak mengklaim acceptance adapter/CRUD/browser M2, grants tabel aplikasi baru, HTTPS, load test atau CI baru.

## Unreleased — M2 PR0 approval (28 November 2026)

- Pengguna menyatakan "saya approval semua untuk PR0 di M2". Catat PR0 Approved, scope ACAD-001–007,
  D01–D06 serta PRD Academic/amendment ERD/master plan v0.1 Accepted tanpa perubahan desain.
- Sinkronkan gate, indeks, README, riwayat dokumen dan referensi AGENTS lokal (tetap di-ignore).
  Core v1.1 tetap baseline; amendment melengkapi rincian M2, sinkronisasi dictionary/diagram masuk PR1.
- Izin penyusunan PR1 tercatat, belum dilaksanakan pada pencatatan ini. PR2–PR6 belum diizinkan;
  DDL aplikasi tetap menunggu daftar migration, preflight dan izin terpisah, bukan ikut dicentang.
- Tidak mengubah source/test aplikasi, migration/schema/database, Docker, credential/role, permission,
  status M1 atau Git commit/push/merge. Bukti uji sebelumnya tetap bertanggal, bukan hasil run baru.

## Unreleased — M2 planning PR0 (27 November 2026)

- Susun PRD Academic v0.1 untuk ACAD-001–007, amendment ERD 10 tabel, usulan keputusan D01–D06,
  urutan PR0–PR6 dan approval gate terpisah untuk spesifikasi/tahap/DDL. Seluruh proposal menunggu review pengguna.
- Sinkronkan indeks/README dan pengecualian Git ignore terbatas agar empat dokumen M2 baru dapat direview;
  baseline Accepted, secret, dump, data siswa dan dokumen lokal lainnya tidak otomatis dibuka untuk Git.
- Tidak mengubah kode aplikasi, schema/migration, Docker, credential/role database, permission tenant atau approval M1.

## Unreleased — M1 runtime permission fix (26 November 2026)

- Pulihkan akses `www-data` pada log Laravel dan cache Blade existing yang dibuat root; isi log dipertahankan.
- Isolasi storage dan bootstrap cache test dalam direktori `/tmp` privat per run sebelum Artisan boot, termasuk
  subprocess concurrency; cleanup saat sukses/gagal mempertahankan exit code dan `umask 077`.
- Operator lokal, setup/verify dan test CI memakai launcher yang sama; regression probes mencakup isolasi path,
  permission privat, exit code, cleanup terbatas, filter dan penolakan opsi Artisan lain.
- Tidak mengubah schema/migration, kredensial, permission tenant, konfigurasi Docker M0 atau status approval M1.
- Verifikasi lokal: full suite 104 test/816 assertion; probe final 4 test/25 assertion; Pint 135 file; Composer valid;
  6 host smoke dan render layout central sebagai `www-data` lulus. Login browser akun pilot dan CI belum diulang.

## Unreleased — M1 PR6 (approved 25 November 2026)

- Bukti browser publik HTTP ditambahkan: 11 pemeriksaan Chrome headless lulus, dengan 11 screenshot dan manifest
  SHA-256 pada commit aplikasi `78385da`. Runner read-only tanpa dependency baru tersedia untuk pengulangan.
  Ini bukan bukti HTTPS/login/MFA/recovery dua sesi; checkbox demonstrasi lengkap tetap terbuka.
- Browser bawaan gagal sebelum navigasi pada dua percobaan; fallback memakai Chrome lokal terpasang dengan profil
  kosong per kasus. Container lama yang berhenti dinyalakan melalui `docker compose start`, tanpa recreate/migration.
  Pengecualian ignore hanya ditambah untuk PNG publik/manifest; DOM, cookie, certificate/key dan profil tetap tidak dilacak.
- Pengguna memberi approval akhir PR6/M1 pada 25 November 2026. PR6 berstatus Approved dan M1 Closed by owner
  untuk pengembangan lokal/sintetis; kelengkapan evidence teknis dicatat terpisah dan tidak dicentang otomatis.
- Commit remediasi `015e792` sudah tersedia. Metadata run GitHub Actions `45356905502` diverifikasi pada
  25 November: job `smoke` dan `private-database-helper` sukses, termasuk Pint dan All tests.
- README, indeks, architecture gate, checklist, matrix dan laporan penutupan disinkronkan. Riwayat temuan/insiden
  dipertahankan; browser empat host/HTTPS dan keseragaman validasi password tetap follow-up. Bukan izin produksi,
  data siswa nyata, M2, tag/release, Git push atau merge; kode/schema/Docker tidak berubah pada pencatatan approval.
- AGENTS, master plan dan label status/referensi enam baseline aktif disinkronkan secara lokal (tetap di-ignore).
  Metadata ADR-001 yang rusak diperbaiki; bukan approval baseline baru atau perubahan requirement/keputusan teknis.

- Dengan persetujuan pengguna, remediasi F01–F03 menambahkan perubahan password mandiri tenant/central, verifikasi
  password lama, batas byte hasher, forced-change guard, audit atomik, revokasi recovery dan seluruh sesi milik user.
- Write provisioning/lifecycle/resend dialihkan ke contract/action Tenancy dan Identity; dependency Support -> Modules
  dihapus. Mapping permission/staff platform tetap terpisah dari role sekolah; architecture guard ditambahkan.
- Coverage diperluas untuk kegagalan invitation di tengah transaksi, mail transport/resend, collision, XOR creator,
  expiry invitation anggota, replay sesi setelah suspend/role/reset lintas host, dan context job setelah exception.
  Role platform terlarang pada endpoint sekolah mengembalikan 403 sesuai matrix; role tak dikenal biasa tetap 422.
- Operator test mendukung filter terbatas; tidak mengubah jalur migration/seed, schema, Docker M0, atau credential pilot.
  Regresi final lulus 100 test/791 assertion, runtime 2 test/51 assertion dan Pint 134 file; evidence lengkap
  pada §8 final review. Browser/HTTPS masih terbuka; CI commit remediasi kemudian terverifikasi pada 25 November.
- Final review 24 November 2026 mencatat 75 test/513 assertion, integrasi runtime 2 test/43 assertion,
  Pint 110 file, tiga test JavaScript dan enam pemeriksaan HTTP lulus; CI commit `b07ebdf` terverifikasi sukses.
  Ini snapshot sebelum remediasi, bukan hasil CI kode baru.
- Acceptance checklist dan test matrix dipetakan ke bukti aktual; PR6/M1 tidak disetujui atau ditutup otomatis.
- Laporan `docs/runbooks/M1_FINAL_REVIEW.md`, checklist dan matrix dikecualikan secara spesifik dari ignore docs
  agar bukti review dapat dilacak; dokumen lain, secret dan backup tetap mengikuti pengecualian sebelumnya.
- Persetujuan pengguna untuk PR5 dicatat pada 21 November 2026; saat itu PR6 masih menunggu review.
- Dashboard central menampilkan agregat tenant dan error provisioning yang dibatasi.
- Direktori tenant mendapat pencarian metadata sekolah dan pagination; audit platform memakai permission khusus,
  filter dan pagination, tanpa menampilkan metadata mentah atau data siswa.
- Metadata nama/timezone dapat diubah melalui action tenant dengan izin platform dan audit; slug, hostname, status,
  dan tenant archived tidak dapat diubah lewat action tersebut.
- Pengujian negatif, isolasi host dan concurrency lifecycle diperluas. Prosedur pilot dan gate produksi dicatat
  pada README; M1 belum dinyatakan siap untuk data nyata.
