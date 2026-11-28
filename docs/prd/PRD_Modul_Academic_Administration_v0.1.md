# PRD Academic Administration M2

| Metadata | Nilai |
|---|---|
| Versi | 0.1 |
| Tanggal | 27 November 2026 |
| Status | Accepted — approval PR0 pengguna 28 November 2026 |
| Milestone | M2 Administrasi Sekolah |
| Owner | Academic; integrasi dengan kontrak Identity dan Tenancy |
| Baseline kode | `4b29461`, branch `Milestone-2` |

Admin sekolah harus dapat menyiapkan periode, guru/siswa, rombel, mapel, course dan
roster melalui UI tanpa SQL manual. M2 tidak membuat ulang Laravel, Docker, akun
global, atau sistem autentikasi M1. Scope dan keputusan D01–D06 disetujui pengguna
pada 28 November 2026; bukti dan batas izin tercatat pada
[approval gate](../runbooks/M2_ARCHITECTURE_GATE.md). Approval desain tidak
menjalankan implementasi atau migration pada database aplikasi.

## Acuan dan batas scope

Baseline Accepted: [PRD Master v1.0](PRD_Master_LMS_SaaS_Multi_Sekolah_v1.0.md)
§5.3/§5.4/§15, [ADR-001 v1.0](../adr/ADR_001_Multi_Tenancy_Identity_Architecture_v1.0.md),
[ADR-002 v1.0](../adr/ADR_002_Application_Architecture_Modular_Structure_v1.0.md),
[ERD Core v1.1](../erd/ERD_Core_LMS_SaaS_v1.1.md) §4/§8/§9/§12,
[PRD Auth/RBAC v1.0](PRD_Modul_Authentication_RBAC_v1.0.md) dan
[PRD SaaS Central v1.0](PRD_Modul_SaaS_Central_Tenant_Management_v1.0.md) §7.3.
Versi aktif mengikuti [indeks dokumen](../DOCUMENT_INDEX.md).

| Requirement Master | Scope M2 P0 dan penerimaan inti |
|---|---|
| ACAD-001 | Admin berizin mengubah profil dasar sekolah sendiri, bukan status/host/paket |
| ACAD-002 | Tahun ajaran dan semester, periode aktif tunggal, validasi tanggal, histori |
| ACAD-003 | Rombel per tahun, tingkat 7–9, penetapan wali kelas berhak |
| ACAD-004 | Mapel scoped tenant dengan kode unik |
| ACAD-005 | Profil guru/siswa manual dan CSV; membership/undangan melalui Identity |
| ACAD-006 | Penempatan dan perpindahan rombel dengan histori utuh |
| ACAD-007 | Course, guru pengampu dan roster siswa, konsisten dengan tahun/semester/kelas |

Roster `course_enrollments` adalah kebutuhan P0 dari ERD §4, bukan implementasi materi
LMS-001–004. Promosi tahun massal dan dashboard wali kelas penuh (ACAD-008/009 P1),
materi/file/tugas/nilai, ujian, billing, custom domain, SSO dan pilot data nyata tidak
termasuk M2. Menjaga histori perpindahan sederhana ACAD-006 tetap P0.

## Kontrol akses dan kontrak modul

Semua akses sekolah mengikuti host terverifikasi, tenant aktif, user/membership
aktif, kedua `auth_version`, permission dan relasi resource. ID tenant dari browser
bukan sumber kebenaran. Binding ULID harus scoped sebelum query resource; objek
sekolah lain menghasilkan 404 tanpa metadata. Aksi yang tidak berizin ditolak 403.

| Aksi | Permission existing dan relasi tambahan |
|---|---|
| Profil dasar sekolah | `school.view` / `school.update`, target dari context server |
| Tahun dan semester | `academic.manage` |
| Daftar/kelola profil guru dan siswa | `academic.manage`; `members.view` untuk identitas anggota yang ditampilkan |
| Buat undangan / ubah role/status | `members.invite` / `members.assign_roles` / `members.suspend` sesuai aksi, tetap melalui Identity |
| Rombel dan penempatan | `classrooms.manage`; baca `classrooms.view`, wali kelas hanya kelas yang ditugaskan |
| Mapel | `subjects.manage` |
| Administrasi course, assignment dan roster | `courses.manage` |
| Guru/siswa membaca course miliknya | `learning.view` dan assignment/enrollment efektif; bukan akses direktori seluruh sekolah |
| CSV profil / akun baru | `academic.manage` + `members.view`; tambahan `members.invite` untuk akun baru |

Admin sekolah tidak otomatis menjadi guru pengampu. Wali kelas tidak otomatis
menjadi penilai. Platform staff tidak mendapat bypass akademik. Ringkasan onboarding
central hanya boolean kesiapan, tanpa nama/username/NIS/roster siswa.

Academic memiliki 10 tabel pada amendment ERD. Tenancy tetap memiliki `tenants` dan
`tenant_settings`; Identity tetap memiliki credential, membership, role dan undangan.
Perubahan lintas modul memakai action/contract pemilik dalam satu transaksi jika
harus atomik. Academic tidak mengimpor model Identity untuk write, dan Identity tidak
bergantung balik pada Academic. Support tetap independen dari Modules.

Kontrak baca eligibility membership, edit profil sekolah oleh school admin, dan
query course/enrollment belum tersedia pada baseline. PR1 merinci DTO, otorisasi,
locking dan boundary tests sebelum implementasi. Query nama anggota harus melalui
port Identity yang scoped/bounded agar tidak menjadi query global atau N+1.

## Keputusan D01 periode akademik

- Tahun/semester memakai `draft`, `active`, `closed`. Closed tidak dapat dibuka ulang
  atau diedit pada M2; metadata/tanggal yang direferensikan tidak diubah sembarang.
  Transisi: draft → active → closed; draft → closed boleh untuk membatalkan rencana
  yang belum dipakai. Tidak ada active → draft atau closed → active.
- Satu tahun aktif per tenant mengikuti partial unique existing ERD. Tambahan M2:
  satu semester aktif per tahun; semester aktif hanya boleh pada tahun aktif.
- Tanggal semester berada dalam tahun, awal < akhir, dan semester ganjil/genap dalam
  tahun yang sama tidak tumpang tindih. Tanggal ditampilkan sebagai kalender sekolah.
- Aktivasi tidak otomatis menutup periode lain: bila masih ada periode aktif yang
  konflik, kembalikan 409 dan minta admin menutupnya secara eksplisit.
- Penutupan semester mensyaratkan course pada semester tersebut telah closed;
  penutupan tahun mensyaratkan seluruh semester yang ada telah closed. Tidak menghapus histori.
- Transaksi mengunci tenant lalu periode dengan urutan konsisten; recheck status
  dan permission di dalam transaksi. Partial unique melindungi race semester/tahun.

## Keputusan D02 profil dan eligibility

- NIS dan employee code opsional, berupa teks agar nol di depan tidak hilang;
  kosong dinormalisasi menjadi null. NISN/tanggal lahir/kontak orang tua tidak dikumpulkan.
- Profil dapat disiapkan untuk membership invited dengan status inactive. Aktivasi
  profil hanya saat user dan membership aktif serta memiliki role student/teacher.
- Aktivasi akun tidak otomatis mengaktifkan profil; admin melakukan langkah tersebut
  melalui Academic. UI membedakan status akun, membership dan profil.
- Satu membership boleh memiliki kedua jenis profil bila memiliki kedua role. Status
  profil tidak mengubah role, password atau status akun global.
- Penugasan guru/wali kelas dan enrollment siswa memerlukan eligibility efektif
  saat mutasi dan saat akses. Wali kelas memerlukan role teacher + homeroom_teacher.
- Role/status yang dicabut segera menghilangkan akses efektif, walaupun record profil
  atau assignment masih tersimpan untuk histori. Policy tidak mengandalkan status
  profil saja atau snapshot/cache assignment yang tidak diinvalidasi.

## Keputusan D03 course dan pengampu

- Course = mapel + rombel + semester dalam tahun yang sama, unik sesuai ERD.
- Status course `draft`, `active`, `closed`; closed read-only, tanpa reopen M2.
  Transisi draft → active → closed, atau draft → closed untuk membatalkan rencana.
- Aktivasi memerlukan tahun/semester aktif, mapel aktif, dan minimal satu pengampu
  efektif. Course boleh disiapkan tanpa siswa, lalu roster diisi secara eksplisit.
- `assignment_role='teacher'` saja pada M2; beberapa pengampu mempunyai scope course
  yang sama, tanpa memperkenalkan role lead/assistant atau permission baru.
- Penonaktifan pengampu aktif terakhir pada course active ditolak; ganti pengampu
  dalam transaksi. Bila user/role dinonaktifkan melalui Identity, akses tetap ditolak
  segera dan UI menandai course perlu pengampu pengganti, bukan memblokir revocation.
- Mengganti tahun/semester/rombel/mapel course yang sudah active dilarang. Penutupan
  course tidak menghapus assignment, roster maupun data pembelajaran masa depan.

## Keputusan D04 penempatan dan histori roster

- Satu penempatan rombel active per siswa per tahun, dengan tanggal masuk/keluar.
  Status penempatan `active` atau `left`; tidak hard-delete penempatan lama.
- Transfer dalam tahun yang sama mengunci data terkait lalu menutup penempatan lama
  dan membuat yang baru secara atomik. Gagal sebagian membatalkan seluruh transfer.
- Preview transfer menampilkan course asal yang belum closed dan memerlukan konfirmasi
  bahwa roster aktifnya menjadi withdrawn dalam transaksi yang sama. Course closed
  dan histori lama tidak diubah. Ini bukan penghapusan submission/grade/attempt.
- Roster rombel baru tidak otomatis disalin ke course. Admin memilih enrollment baru
  setelah transfer; sistem tidak menebak mata pelajaran yang diikuti siswa.
- Untuk enrollment baru, isi `source_classroom_enrollment_id` dari penempatan active
  siswa pada classroom course. FK komposit melindungi tenant+siswa; action memvalidasi
  kecocokan kelas/tahun. Kolom tetap nullable sesuai baseline untuk kompatibilitas.
- Withdraw mempertahankan row. Re-enroll memakai row course/siswa yang sama, memeriksa
  ulang placement, mempertahankan `enrolled_at` awal, dan mengaudit transisi beserta
  nilai `withdrawn_at` sebelumnya sebelum dikosongkan. Tidak mengganti ID histori.
- Siswa withdrawn tidak mendapat akses course aktif. Pembacaan histori milik sendiri
  oleh consumer M3/M4 harus melalui kontrak/policy terpisah, bukan akses roster umum.

## Keputusan D05 impor CSV terkontrol

M2 mendukung profil anggota existing dan undangan akun baru tanpa email, bukan
auto-link identitas global. Pengguna existing lintas sekolah tetap melalui alur
join-existing M1; impor profil baru dilakukan setelah membership yang sah tersedia.

Header CSV: `operation,profile_type,local_username,display_name,nis,employee_code`.
`operation` adalah `profile_existing` atau `invite_new`; `profile_type` student/teacher.
Nama wajib untuk akun baru; mode existing tidak memperbarui nama/username/role Identity.
Kolom yang tidak relevan untuk jenis profil harus kosong. Kolom password, token,
tenant_id, user_id, email, NISN dan kolom tidak dikenal ditolak.

1. Admin mengunggah CSV UTF-8, koma, header tetap; batas configurable 500 baris,
   1 MiB dan TTL preview 15 menit. Tidak memasang parser/package baru pada PR0.
2. Parser memvalidasi seluruh baris, duplikasi internal, username/role, kode profil dan
   konflik database. UI preview menunjukkan nomor baris dan error yang dapat diperbaiki;
   tidak ada write bisnis pada preview. Profil existing identik boleh no-op, bukan update diam-diam.
3. File disimpan privat dengan path server-derived tenant; handle preview terikat tenant,
   actor membership dan kedua auth version. Digest payload diverifikasi saat konfirmasi.
   Jangan memakai nama/path file dari client atau menyimpan file di public.
4. Konfirmasi memakai CSRF, cek ulang izin/eligibility dan validasi DB; transaksi all-or-nothing
   dengan lock tenant. Tidak memakai best-effort parsial atau job impor pada versi awal.
5. Retry handle yang sama diperiksa lewat audit marker `academic.import.applied` yang
   ditulis dalam transaksi bisnis, berisi handle non-rahasia, actor dan hitungan saja.
   Lock tenant menserialisasi claim; cache bukan satu-satunya bukti sukses. Commit DB
   sukses tetapi cache gagal tidak boleh mengimpor dua kali. Audit gagal harus rollback.
   Setelah otorisasi actor/tenant saat ini, periksa marker milik actor tersebut sebelum
   meminta ulang file preview: marker committed mengembalikan ringkasan sukses saja,
   bukan melakukan write baru. Handle pending tetap membutuhkan file/cache dan kedua
   snapshot version yang valid; handle actor lain tidak boleh mengungkap hasilnya.
6. `invite_new` memanggil kontrak Identity untuk pending user, invited membership dan
   invitation, lalu membuat profil inactive. Kode aktivasi tidak ada pada CSV/report/log.
   Perlu port reissue undangan anggota pending yang diaudit agar admin menyerahkan
   kode baru sekali tampil per penerima melalui UI privat M1. Port ini BELUM ada di
   baseline, harus dibuat dan diuji pada PR5; bukan klaim resend M1 sudah mencakupnya.
7. Hasil hanya nomor baris, kode hasil dan hitungan minimal. Tidak menyediakan ekspor
   CSV hasil pada M2, sehingga tidak mengeluarkan sel formula bebas. Preview/report
   server-side dan file sementara kedaluwarsa/dihapus; audit marker sanitasi tetap ada.
   Jika cache/file hilang sebelum commit, minta preview ulang; jangan menebak hasil.

Tidak menambah `import_batches`, staging table, outbox atau tabel lain di luar 10 tabel
Academic. Durable notification/outbox tetap gate terpisah. Pengiriman eksternal, jika
dipakai, sesudah commit; kegagalan tidak boleh membuat ulang akun atau tenant.

## Keputusan D06 profil dasar sekolah

UI school admin mengelola `tenants.name`, `tenants.timezone` dan `tenant_settings.accent_color`
melalui port Tenancy dengan `school.update`. Timezone harus valid; warna mengikuti check
hex existing. Perubahan timezone hanya memengaruhi tampilan, bukan memindahkan timestamp
UTC. Slug/hostname, status layanan, paket dan onboarding flags tidak boleh di-mass-assign.
Upload logo/branding lengkap ditunda, bukan membuka storage publik tanpa rancangan file.

## UI dan hasil akhir pengguna

Navigasi sekolah: Profil Sekolah, Tahun dan Semester, Guru, Siswa, Mapel, Rombel, Course,
serta Impor CSV. Gunakan Blade dan komponen existing, Bahasa Indonesia, responsive dan
pagination server-side; tampilkan tenant/periode aktif pada setiap halaman akademik.
Validasi mempertahankan input aman; error tidak menampilkan SQL, token, credential atau
identitas sekolah lain. Konfirmasi wajib untuk close, withdraw dan transfer.

Alur penerimaan tiga sekolah sintetis: admin login → profil/periode → undang atau pilih
anggota M1 → profil guru/siswa → mapel/rombel/wali kelas → penempatan → course/pengampu →
roster → preview dan konfirmasi CSV → periksa audit dan penolakan akses silang.
Admin menyelesaikannya tanpa tinker, SQL manual, perubahan `.env`, atau reset database.

## Dampak schema dan pengujian

[Amendment ERD Accepted](../erd/ERD_M2_Academic_Amendment_v0.1.md) merinci tabel,
candidate keys, status dan perbedaan baseline. Tidak ada schema yang diterapkan oleh PRD ini.
Transaksi kritis memakai audit atomik dan no hard-delete. Policy assignment/enrollment
dibaca ulang pada akses; M2 tidak menambah cache permission akademik yang stale.

[Master plan](../runbooks/M2_MASTER_PLAN.md) memuat urutan PR, negative tests PostgreSQL,
concurrency, CSV failure/retry dan gate DDL. Semua fixture, screenshot dan impor uji harus
sintetis. Bukti browser HTTPS M1 yang belum lengkap tidak dianggap selesai oleh M2.

## Riwayat versi

| Versi | Tanggal | Perubahan |
|---|---|---|
| 0.1 | 2026-11-27 | Proposal scope ACAD-001–007, D01–D06, integrasi M1 dan acceptance M2; belum implementasi |
| 0.1 — approval | 2026-11-28 | Pengguna menyetujui semua PR0; status Accepted, D01–D06 tidak diubah; izin persiapan PR1 dan gate DDL terpisah dicatat |
