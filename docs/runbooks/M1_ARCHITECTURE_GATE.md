# M1 ARCHITECTURE GATE — Persetujuan sebelum migration final

## Status review saat ini

- `docs/adr/ADR_001_Multi_Tenancy_Identity_Architecture_v1.0.md`: **Accepted**
- `docs/adr/ADR_002_Application_Architecture_Modular_Structure_v1.0.md`: **Accepted**
- `docs/erd/ERD_Core_LMS_SaaS_v1.1.md`: **Accepted**

Baseline arsitektur dan ERD di atas telah disetujui oleh pengguna pada 8 November 2026. Catatan ini menyelaraskan repository dengan approval tersebut; ini bukan persetujuan otomatis oleh Codex. Risiko produksi yang masih terbuka tetap harus ditangani melalui milestone dan gate terkait.

## Ringkasan milestone — 25 November 2026

**PR6: Approved. M1: Closed by owner**, berdasarkan approval akhir pengguna untuk pengembangan lokal/sintetis.
Commit yang disetujui: `015e792f350536db14f4be049624e6d0e82e95b2` (`Milestone-1`). CI commit ini terverifikasi
sukses; browser empat host/HTTPS belum mempunyai evidence. Kelengkapan acceptance teknis tetap dicatat terpisah
dari keputusan penutupan pemilik; tidak ada waiver requirement atau izin produksi/data siswa nyata/M2.

Record lengkap ada pada bagian **Approval akhir PR6/M1 — 25 November 2026** di bawah. Bagian review terdahulu
adalah riwayat bertanggal; status "menunggu/belum disetujui" di sana tidak membatalkan approval akhir ini.

Pembaruan evidence browser: 11 pemeriksaan Chrome publik/tamu HTTP lulus dengan screenshot/manifest (§10 laporan).
Ini bukan kelulusan HTTPS, MFA/login atau sesi lintas sekolah; record approval dan batas acceptance tetap sama.

## Keputusan yang harus direview manusia

| Area | Accepted baseline | Hal yang diverifikasi |
|---|---|---|
| Tenant | Satu sekolah = satu tenant | Sesuai model komersial? |
| Data | PostgreSQL shared DB + `tenant_id` | Composite FK dan pengujian negatif realistis? |
| Identitas | `users` global + `tenant_memberships` per sekolah | Join-existing dan reset aman? |
| Session | Login per host, cookie host-only | Produksi HTTPS, session backend, multi-host revocation |
| Platform | `platform_staff` terpisah dari role sekolah | Tidak ada universal bypass akademik |
| Codebase | `app/Modules` (ADR-002) | Dependency graph dan owner tabel jelas |
| Schema M1 | 15 tabel, 4 batch migration | Contract nullable/status/idemp benar? |
| Risiko terbuka | RLS, outbox, recovery tanpa email, operasi MFA lanjutan (verifikasi manual, HTTPS, backup secret) | Ada owner, milestone, dan gate produksi |

## Approval record

```text
ADR-001: [x] Accepted [ ] Changes requested — reviewer: user (chat approval) date: 2026-11-08
ADR-002: [x] Accepted [ ] Changes requested — reviewer: user (chat approval) date: 2026-11-08
ERD v1.1: [x] Accepted [ ] Changes requested — reviewer: user (chat approval) date: 2026-11-08
Pending decisions / follow-up ADR: RLS, outbox, dan recovery tanpa email tetap menjadi follow-up produksi; operasi MFA
reset/re-enrollment sekarang memiliki endpoint terkontrol dan audit, tetapi prosedur verifikasi manual, HTTPS, session backend,
serta backup secret tetap menjadi gate produksi.
PR/Issue review URL: tidak tersedia; approval dicatat pada percakapan pengguna dan file ini.
```

## PR3 review evidence

PR3 telah direview secara teknis melalui evidence berikut:

- `M1AuthRbacTest`: 13 test, 85 assertion, lulus.
- `M1HostContextTest`: 6 test, 23 assertion, lulus sebagai bagian full suite.
- Full suite M0/M1: 33 test, 144 assertion, lulus.
- Pint: 65 file, lulus.
- Host routing: central allowlist, tenant resolver, dan unknown host negative test lulus.
- Platform permission mapping: permission platform tidak diberikan melalui role tenant; central dashboard memakai middleware permission.
- Central MFA: TOTP enrollment/challenge, secret terenkripsi, recovery code single-use, anti-replay, rate limit, dan fail-closed production.
- Central MFA reset/re-enrollment: `platform.staff.mfa_reset` hanya untuk super admin, target memakai public ULID, credential/recovery lama dihapus, `users.auth_version` dinaikkan, dan `auth.mfa.reset` dicatat atomik sebelum target diarahkan ke enrollment ulang.
- HTTP host smoke check: `localhost /` → 200, `admin.platform.test /login` → 200, `unknown.platform.test /login` → 404, dan `127.0.0.1 /` → 404.

Status: **technical review passed; PR3 disetujui pengguna pada 2026-11-12**. Manual browser verification masih menunggu environment browser yang dapat mengakses host lokal. PR4 belum dimulai dan route provisioning belum diubah.

Migration `2026_11_09_000500_create_platform_mfa_credentials` telah berstatus `Ran` pada database Docker lokal.

Approval record PR3:

```text
PR3: [x] Approved — reviewer: user (chat approval) date: 2026-11-12
Scope: Auth/RBAC, platform permission mapping, central MFA, audited MFA reset/re-enrollment.
Next gate: review dan approval PR4 sebelum memulai PR5.
```

## PR4 review evidence

PR4 implementation evidence:

- `M1ProvisioningTest`: 9 test, 83 assertion, lulus.
- Full suite M0/M1: 42 test, 227 assertion, lulus.
- Pint: 80 file, lulus.
- Migration status: seluruh 5 migration berstatus `Ran` pada PostgreSQL Docker.
- Route provisioning dan lifecycle memakai `central.permission` sesuai action.
- Provisioning membuat tenant pending, default domain, settings, pending admin, invitation hash, idempotency record,
  dan audit dalam transaction.
- Activation hanya menerima host tenant pending; wrong-host, expired, dan replay ditolak tanpa side effect.
- Lifecycle mengharuskan admin active untuk activate/restore, menaikkan membership auth version saat suspend/archive,
  serta menolak archive sebelum checklist offboarding selesai.
- Token invitation hanya tersedia pada environment local/testing dan tidak masuk audit metadata.

Status: **PR4 disetujui pengguna pada 2026-11-18** melalui percakapan.

## PR4 hardening follow-up evidence

- Delivery invitation email dikirim setelah transaksi provisioning selesai; kegagalan tidak membatalkan tenant, dicatat
  sebagai audit `tenant.invitation.delivery_failed`, dan dapat diulang dengan rotasi token melalui endpoint resend.
- `TenantProvisioningService` memakai PostgreSQL advisory transaction lock untuk actor/idempotency key. Test dua proses
  paralel membuktikan satu tenant tercipta dan request lain melakukan replay (`M1ProvisioningConcurrencyTest`: 1 test,
  7 assertion, lulus).
- UI central Blade tersedia untuk directory, detail tenant, provisioning, resend invitation, dan lifecycle transition.
- `scripts/m1-host-smoke.ps1` memeriksa central host, unknown host, loopback, dan sampai tiga tenant aktif menggunakan
  `curl --resolve`, tanpa perlu mengubah Windows hosts file.
- Test delivery/resend dan UI ditambahkan ke `M1ProvisioningTest`; seluruh data tetap sintetis.

Status follow-up: **PR4 beserta tindak lanjutnya disetujui pengguna pada 2026-11-18**.

Catatan operasional 2026-11-19: status migration lokal di atas merupakan bukti historis, bukan status saat ini.
Ketika pengujian PR5 dijalankan, konfigurasi environment menyebabkan migration test menargetkan database lokal
`lms_saas` dan menghapus tabel aplikasi; saat diperiksa hanya tabel `migrations` yang tersisa. Pemulihan belum
dilakukan dan menunggu informasi backup atau persetujuan eksplisit pengguna untuk membuat ulang data dummy.
Pengujian berikutnya diarahkan dan diverifikasi terhadap database khusus `lms_saas_test`.
Pada 2026-11-19 pengguna mengizinkan pembuatan ulang database lokal. `php artisan migrate --force` dan
`php artisan db:seed --force` dijalankan pada `lms_saas`: lima migration berstatus `Ran`, katalog berisi
4 role dan 38 permission, dan terdapat 0 tenant serta 0 user. Ini membangun schema baru, **bukan** memulihkan
data yang terhapus sebelumnya. Backup data lama belum ditemukan dalam folder proyek.

```text
PR4: [x] Approved — reviewer: user (chat approval) date: 2026-11-18
Scope: provisioning, activation, lifecycle, invitation delivery dan hardening PR4.
Next gate: review dan approval pengguna atas PR5 sebelum memulai PR6.
```

## PR5 — review dan approval

- Role/status membership scoped ke host tenant, perubahan menaikkan membership auth version dan tercatat dalam audit;
  penghapusan admin sekolah aktif terakhir dicegah.
- Undangan akun baru tanpa email memakai token hash, TTL, dan aktivasi tenant-bound single-use.
- School-assisted recovery dibatasi ke siswa tanpa email dengan satu membership historis dan tanpa akun platform;
  konsumsi token menaikkan users auth version.
- Pengaitan akun existing menggunakan undangan tenant-bound dan pembuktian kredensial global; membership baru dibuat
  setelah verifikasi. Recovery email memakai alamat terverifikasi, token hash sekali pakai, dan pencabutan sesi global.
- UI undangan offline dan prosedurnya tersedia di `README.md`.
- Alur recovery berbantuan dua sesi telah disetujui pengguna pada 2026-11-19 dan diimplementasikan; token tetap
  berada pada sesi siswa, bukan respons admin. PR5 disetujui pengguna pada 2026-11-21 melalui percakapan.
- Pengujian akhir pada database khusus `lms_saas_test`: 64 test dan 412 assertion lulus, termasuk dua proses paralel
  untuk recovery email, pengaitan akun existing, recovery sekolah, approval Redis, dan provisioning. Tiga uji
  JavaScript terisolasi lulus. Pint lulus untuk 100 file; Composer valid.
  Template `SelfRecoveryMail` juga dikirim langsung ke Mailpit lokal melalui SMTP menggunakan alamat dan tautan sintetis;
  isi email dan tautan terverifikasi. Ini bukan uji alur HTTP penuh maupun delivery provider produksi. Verifikasi visual
  dan end-to-end pada browser HTTPS serta pengiriman melalui provider produksi belum dilakukan.

### Proposal kanal recovery tanpa email — disetujui untuk implementasi

Pengguna menyetujui alur dua sesi pada 2026-11-19 melalui percakapan. Persetujuan ini tidak mengubah status Accepted
ERD/ADR dan bukan approval PR5. Rancangan:

1. Siswa menggunakan perangkat/sesi browser privat pada host sekolah untuk membuat permintaan pemulihan dan
   menghasilkan rahasia acak di browsernya sendiri. Hanya ID permintaan non-rahasia yang ditunjukkan kepada admin.
2. Admin berpermission memeriksa identitas siswa secara tatap muka sesuai SOP sekolah, memeriksa target permintaan
   di tenant yang benar, lalu menyetujui permintaan tersebut. Respons dan tampilan admin tidak berisi rahasia/token siswa.
3. Siswa tetap memakai sesi privatnya untuk menetapkan password. Server mengikat persetujuan dan pembuktian rahasia
   pada permintaan, user, dan tenant; penggunaan hanya sekali, kedaluwarsa, dibatasi, diaudit, dan membatalkan sesi lama.
4. Status permintaan tidak menyimpan `token_hash` sebelum verifikasi yang sah. Salah satu opsi teknis adalah menyimpan
   komitmen hash sementara di cache tenant-scoped dengan TTL; kehilangan cache menyebabkan permintaan gagal aman dan
   harus diulang. Detail atomicity, persaingan akses, retensi, batas percobaan, dan penanganan kegagalan wajib diuji.
5. Kebijakan satu membership historis, bukan staff platform, dan kelayakan siswa diperiksa ulang saat persetujuan serta
   konsumsi. Rahasia tidak masuk audit, URL, log, atau respons admin; produksi memerlukan HTTPS dan browser yang mendukung
   cryptographic randomness. Prosedur juga harus menjelaskan perangkat privat, identifikasi, dan bantuan jika siswa
   tidak memiliki perangkat yang memadai.

Endpoint lama yang mengirim token kepada admin dihapus dari routing. Pengguna menyetujui PR5 dan mengizinkan PR6 pada
2026-11-21 melalui percakapan. Persetujuan ini tidak menutup risiko produksi atau menggantikan verifikasi browser HTTPS.

```text
PR5: [x] Approved — reviewer: user (chat approval) date: 2026-11-21
Scope: membership, invitation, account linking, recovery, audit dan hardening PR5.
Next gate: review dan approval PR6 sebelum melanjutkan ke M2.
```

## Riwayat PR6 — bukti implementasi untuk review sebelum approval akhir

- Dashboard central menampilkan agregat tenant, onboarding dan kegagalan provisioning yang disaring; directory
  mendukung pencarian, filter, pagination, dan status undangan. Pembaruan nama/timezone memeriksa permission,
  dikunci dalam transaksi dan diaudit; slug, host dan status tidak dapat diubah melalui operasi tersebut.
- Viewer audit memakai permission platform dan hanya menampilkan field yang dipilih, tanpa metadata audit atau
  identifier sensitif. Filter hasil hanya mengklasifikasikan action dengan suffix yang dikenali; lainnya `other`.
  Penulisan audit menolak key metadata sensitif termasuk yang bersarang, tetapi belum menjamin bahwa nilai dengan
  key lain tidak mengandung rahasia maupun menjamin append-only di level database.
- Full suite final pada database khusus `lms_saas_test`: **75 test, 513 assertion lulus**; termasuk isolasi host
  untuk tiga tenant sintetis dan central, negative test permission, serta uji dua proses untuk aktivasi dan suspend.
  `M1PlatformDashboardTest`: 9 test, 86 assertion lulus. Test JavaScript recovery: 3 lulus. `pint --test`: 108 file
  lulus; `composer validate --no-check-publish`: valid. HTTP smoke lokal: login central 200, unknown host 404.
- Pemeriksaan read-only `lms_saas` setelah pengujian menunjukkan 5 migration, 0 tenant, 0 user. Tidak ada migration
  baru, perubahan konfigurasi Docker, atau data siswa nyata. Catatan kehilangan data historis PR5 di atas tetap berlaku.
- Runbook pilot dan gate operasional tersedia pada `README.md`. Browser automation tidak tersedia di environment
  ini (`missing field sandboxPolicy`); verifikasi visual/HTTPS dan smoke manual dengan tiga sekolah sintetis pada
  empat host belum dilakukan. Akun platform dan tenant demo belum dibuat di database utama. Bukti GitHub Actions
  terbaru, latihan backup/restore, kebijakan audit append-only/retensi, dan keputusan RLS/outbox masih terbuka.

Status: **implementasi tersedia untuk review; PR6 belum disetujui, dan M1 belum dinyatakan selesai atau siap produksi**.
Tidak ada persetujuan otomatis untuk PR6 atau tahap berikutnya.
Catatan: angka database dan pilot pada bagian PR6 di atas adalah bukti historis saat review itu ditulis; setelahnya
pengguna membuat sekolah sintetis dan latihan backup/restore lokal telah dicatat di `README.md`.

## Hardening audit PostgreSQL — diterapkan lokal, gate produksi masih terbuka

Pada pemeriksaan awal, koneksi aplikasi memakai `lms_user`; role tersebut adalah **superuser** dan pemilik kedua tabel
audit, sehingga `REVOKE` dari role tersebut tidak efektif. Baseline ADR-001 mengarahkan role request non-superuser/
non-owner. Rancangan di bawah disetujui pengguna melalui percakapan pada 2026-11-21 dan diterapkan pada lingkungan
lokal pada 2026-11-22; ini tidak mengubah status approval PR6 atau membuktikan kesiapan produksi.

Rancangan yang disetujui pengguna:

1. Pisahkan role runtime aplikasi/worker/scheduler dari role owner/migrasi. Runtime harus `NOSUPERUSER`, `NOBYPASSRLS`,
   bukan owner schema/tabel dan tidak dapat menjadi anggota atau `SET ROLE` ke owner. Kredensial owner/migrasi tidak
   tersedia di runtime normal; tentukan prosedur migrasi dan pengujian menggunakan kredensial terpisah yang aman.
2. Untuk kedua tabel `platform_audit_logs` dan `tenant_audit_logs`, runtime hanya mendapat hak yang dibutuhkan untuk
   membaca dan menambah audit beserta akses sequence; tidak mendapat `UPDATE`, `DELETE`, `TRUNCATE`, kepemilikan,
   hak mengubah tabel/trigger, atau jalur grant turunan. Periksa pula grant ke `PUBLIC`, kepemilikan schema,
   keanggotaan role, default privileges, migrasi berikutnya, serta hak minimum tabel lain yang memang dipakai aplikasi.
3. Perubahan koneksi aplikasi/worker/scheduler perlu memisahkan konfigurasi runtime dari kredensial PostgreSQL bootstrap
   di Docker; provisioning dan pergantian secret harus lokal, tidak dicetak, dan tidak disimpan di Git. Jaga database
   test, volume, tenant dan data yang ada; lakukan preflight, backup yang diverifikasi, rollout dan rollback aman,
   lalu uji app, migration/deploy, tenant isolation, backup/restore dan CI. Jangan mengganti role pada database lokal
   atau menjalankan migration/DDL final sebelum rancangan disetujui dan bukti pengujian siap.
4. Proteksi ini membatasi role runtime; superuser/owner masih dapat memodifikasi audit, dan role yang boleh `INSERT`
   masih bisa memalsukan event. Ini bukan bukti anti-tamper absolut, tidak menggantikan RLS, kontrol akses baca,
   perlindungan nilai sensitif metadata, retensi, pengawasan, atau rencana backup audit terpisah.

**Bukti proof of concept, bukan hardening aplikasi:** `tests/Support/m1_audit_privilege_probe.sql` dijalankan pada
`lms_saas_test` menggunakan transaksi yang di-rollback. Role percobaan non-login berhasil `SELECT`/`INSERT` dan
ditolak saat `UPDATE`, `DELETE`, `TRUNCATE`, atau mengubah trigger pada kedua tabel. FK audit lintas tenant menolak
membership tenant lain. Uji terhadap database `postgres` ditolak oleh guard. Role percobaan dan baris sintetis tidak
tersisa. Percobaan awal memakai sequence di database test yang kosong sehingga nomor sequence maju meskipun transaksi
di-rollback; script yang disimpan memakai ID eksplisit dan uji ulang membuktikan sequence tidak berubah. Database
aplikasi tidak menjadi target pada proof of concept tersebut.

**Persiapan penerapan:** backup lokal baru diverifikasi dengan checksum. Skrip
`database/security/m1_audit_runtime_grants.sql` dan template `docker/compose.audit-local.override.yml` disiapkan;
`tests/Support/m1_audit_runtime_grants_test.ps1` menguji grant dengan role sintetis pada `lms_saas_test` dan rollback.
Uji positif `SELECT`/`INSERT`, penolakan `UPDATE`/`DELETE`/`TRUNCATE`/`ALTER`, akses sequence, dan penolakan pembuatan
objek schema lulus. Guard menolak target `postgres`. Template Docker lulus `config --quiet`.
Skrip grant saat ini memberikan DML untuk tabel aplikasi non-audit yang sudah ada dan bukan bukti least-privilege
per modul; tidak ada default grant untuk tabel baru, sehingga proses deployment harus memperbarui dan menguji grant
setelah migration sebelum dialihkan ke runtime.
Pemeriksaan keamanan alat menolak langkah yang berpotensi menampilkan password dalam output; kredensial kemudian
disiapkan secara privat di luar mount aplikasi tanpa dikirim melalui chat. Pada 2026-11-22 backup lokal baru telah
dibuat dan checksum diverifikasi; role `lms_runtime` telah dibuat dan grant diterapkan pada database lokal. Pengujian
atribut role dan hak kedua tabel audit lulus. Pemilik mengubah `.env` secara privat. App, worker dan scheduler telah
dibuat ulang dan masing-masing terverifikasi terhubung sebagai `lms_runtime`; file secret owner tidak di-mount ke
ketiganya. Operator migration berjalan tanpa migration tertunda dan menerapkan ulang grant. Volume PostgreSQL tetap
sama; jumlah tenant dan user sintetis setelah restart adalah tiga dan empat.

Setelah pengalihan, `scripts/m1-operator.ps1 test` lulus 75 test/513 assertion menggunakan owner pada database test;
pengujian ini bukan bukti seluruh alur mutasi aplikasi sudah diuji dengan role runtime. Pemeriksaan runtime pada app,
worker dan scheduler lulus, tes grant sintetis rollback lulus, serta host smoke HTTP lulus untuk central, tiga tenant,
host tak dikenal, dan loopback. Pint lulus untuk 109 file; `composer validate --no-check-publish` lulus.

Pada 2026-11-23, `tests/Support/m1-runtime-integration.ps1` lulus 2 tes/43 assertion pada PostgreSQL tmpfs dan jaringan
Docker internal yang terpisah dari server utama. Server dan kredensialnya sintetis; guard grant diadaptasi hanya untuk
nama database test. Schema dan fixture disiapkan owner, lalu Laravel benar-benar login sebagai `lms_runtime`. Cakupan:
penolakan marker isolasi salah, login dua sekolah dan central, penolakan sesi lintas tenant, provisioning idempotent,
aktivasi terikat host, pencatatan kedua audit, serta penolakan UPDATE/DELETE audit dan pemeriksaan hak TRUNCATE.
Kontainer dan jaringan sementara dibersihkan. MFA dinonaktifkan hanya pada aplikasi uji ini; tes tidak membuktikan
browser HTTPS, perilaku seluruh job/worker, seluruh grant modul, atau isolasi RLS.

Selama rollout, penggantian entrypoint Compose menghilangkan command bawaan image dan menyebabkan PostgreSQL restart
berulang. Entrypoint diperbaiki agar default ke `postgres` jika tidak ada argumen; database kembali sehat tanpa menghapus
volume. Setelah app dibuat ulang, HTTP sempat 502; restart web memuat ulang alamat upstream. Backup sebelum rollout
tersedia pada `storage/app/private/backups/lms-saas-pre-role-20261122-005616.dump`; backup mengandung data seluruh tenant
dan harus tetap privat. Restore drill sebelumnya tidak menguji backup khusus ini setelah role baru dibuat.

Pembatasan ini tidak melindungi dari owner/superuser atau event palsu yang di-INSERT oleh runtime. Grant non-audit masih
luas, RLS tidak diterapkan oleh pekerjaan ini, dan instalasi baru/CI belum otomatis menyiapkan pemisahan role. Uji
browser HTTPS, restore drill dengan role baru, serta review produksi tetap terbuka.

### Pembatasan CONNECT database lokal — diterapkan untuk empat database

Pengguna menyetujui melalui chat pembatasan koneksi pada empat database lokal yang sudah ada; `TEMP` dan ACL
`lms_saas` tidak termasuk perubahan. Sebelum perubahan pada 2026-11-23, metadata ACL yang dicatat adalah:

| Database | `datacl` awal |
|---|---|
| `lms_saas` | `{=Tc/lms_user,lms_user=CTc/lms_user,lms_runtime=c/lms_user}` |
| `lms_saas_test` | `NULL` (default PostgreSQL) |
| `lms_saas_restore_drill` | `NULL` (default PostgreSQL) |
| `postgres` | `NULL` (default PostgreSQL) |
| `template1` | `{=c/lms_user,lms_user=CTc/lms_user}` |
| `template0` | `{=c/lms_user,lms_user=CTc/lms_user}`; koneksi dinonaktifkan |

`lms_user` adalah owner/superuser, tidak ada role login non-sistem selain `lms_user` dan `lms_runtime`, dan tidak ada
koneksi klien aktif ke empat target saat preflight. Tiga tenant dan empat user sintetis ada pada database aplikasi;
checksum backup sebelum pemisahan role masih cocok. Untuk pemulihan akses efektif jika rollout gagal setelah commit,
operator berwenang dapat memberikan kembali `CONNECT` kepada `PUBLIC` hanya pada empat database yang dicabut dan
memverifikasi seluruh hak. `GRANT` ulang dapat menghasilkan ACL eksplisit, sehingga tidak menjamin representasi
`datacl` kembali `NULL`; jangan mengubah katalog sistem secara langsung untuk memaksakan bentuk ACL lama.

Setelah persetujuan pengguna pada 2026-11-23, satu transaksi dengan guard awal/akhir mencabut `CONNECT` dari
`PUBLIC` hanya pada `lms_saas_test`, `lms_saas_restore_drill`, `postgres`, dan `template1`. ACL `lms_saas`,
`template0`, dan hak `TEMP` tetap seperti preflight. Koneksi baru menggunakan kredensial runtime benar-benar
ditolak pada empat target dan berhasil pada `lms_saas`; koneksi baru owner melalui kontainer operator sementara
berhasil pada kelima database. Koneksi yang sudah ada tidak otomatis diputus; preflight tidak menemukan klien
aktif pada empat target. App, worker, dan scheduler masing-masing lolos verifikasi runtime/audit; host smoke HTTP
lulus untuk central, tiga tenant, unknown host dan loopback. Operator `test-m0` lulus 3 test/6 assertion; tes
integrasi lintas tenant pada PostgreSQL sintetis terisolasi lulus 2 test/43 assertion. Tes terakhir menggunakan
server sementara dan tidak membuktikan seluruh alur mutasi terhadap database aplikasi lokal.

Perubahan `PUBLIC` memengaruhi semua role, bukan hanya runtime; role tambahan perlu grant eksplisit yang direview.
Database yang dibuat berikutnya tidak otomatis dicakup oleh perubahan empat database ini. Autentikasi `trust`
socket/loopback di dalam kontainer PostgreSQL belum diubah; akses shell kontainer database tetap administratif.

### Perlindungan database baru dan review autentikasi lokal

Helper Windows `scripts/m1-create-private-database.ps1` dan SQL `database/security/m1_create_private_database.sql`
disiapkan untuk database `lms_restore_*` dan `lms_aux_*` yang belum ada. Wrapper membatasi Docker Desktop lokal,
label Compose, volume, dan konfigurasi target. SQL membuat database dengan `ALLOW_CONNECTIONS false`, lalu mencabut
`CONNECT` dari `PUBLIC`, memeriksa hak runtime dan owner, serta membuka database dalam transaksi. Jika transaksi gagal,
database baru tetap tertutup dan tidak dihapus otomatis. Ini tidak mengubah database existing, tidak mencakup database
yang dibuat dengan tool lain, dan tidak mengubah `TEMP`; lihat langkah serta batasan di README.

Pada 2026-11-23, `tests/Support/m1-private-database-test.ps1` lulus 27 pemeriksaan pada PostgreSQL sintetis tanpa
jaringan dan tanpa volume persisten. SQL yang sama diuji untuk pembuatan dua jenis database, penolakan nama/role tidak
aman dan target existing, kegagalan sebelum maupun sesudah `ALTER DATABASE`, serta `pg_dump`/`pg_restore` sintetis.
Owner berhasil terhubung; runtime ditolak sebelum dan sesudah restore; database sumber dan target existing tetap utuh.
`-WhatIf` wrapper lokal lulus dan database pratinjau tidak dibuat. Uji ini tidak menjalankan helper untuk membuat
database baru pada server pengguna dan tidak membuktikan restore backup lokal yang sebenarnya.

Review `pg_hba` pada server lokal menunjukkan `trust` untuk socket dan loopback di dalam kontainer PostgreSQL dan
`scram-sha-256` untuk koneksi jaringan. Percobaan read-only sebagai runtime tanpa password berhasil lewat socket
dan loopback; dari kontainer aplikasi, password sintetis yang salah ditolak. Ini berarti akses menjalankan proses
di kontainer database harus dianggap administratif: aturan `trust` dapat mengizinkan identitas database tanpa
password sesuai hak database. Tidak ada uji penyamaran owner pada server aktif dan aturan `pg_hba` belum diubah.

**Usulan untuk review, belum disetujui:** sebelum mengganti `trust`, inventarisasi dan adaptasi semua operator yang
menggunakan socket (`psql`, grant ulang, init, dump/restore, probe dan helper ini), dengan secret owner privat tanpa
password pada argumen atau log. Uji perubahan, kegagalan credential, bootstrap database baru, healthcheck,
migration/test, backup/restore, serta rollback `pg_hba` di PostgreSQL sintetis; siapkan akses pemulihan dan backup
konfigurasi sebelum perubahan lokal. Perubahan autentikasi aktual memerlukan persetujuan pemilik dan tidak dilakukan
oleh pekerjaan ini.

**Hasil lab sintetis 2026-11-23 — rancangan masih belum disetujui untuk server aktif.**
`tests/Support/m1-scram-synthetic-test.ps1` lulus 15 pemeriksaan pada dua PostgreSQL 17 sementara tanpa jaringan
atau volume persisten. Pengujian mencakup reload fixture SCRAM, password owner/runtime benar dan salah, socket dan
loopback IPv4, SQL pembuatan database privat yang sama, dump/restore sintetis dengan penolakan runtime, serta
rollback ke HBA awal. Cluster kedua menggunakan autentikasi SCRAM sejak `initdb` dan menjalankan init script database
test yang sebenarnya. Kedua container dan data sementaranya dihentikan/dihapus otomatis setelah uji.
`pg_isready` tetap berhasil tanpa password: probe ini mengukur kesiapan server, **bukan** validitas credential.

Rancangan yang perlu direview: gunakan SCRAM untuk koneksi socket dan loopback setelah menginventarisasi seluruh rule
HBA aktual dan proses operator, dengan credential melalui kanal privat yang tidak muncul di argumen atau log. Jangan
menyalin fixture lab ke server aktif: aturan jaringan, replikasi, urutan first-match, hak akses dan owner file harus
disesuaikan serta direview. Simpan backup konfigurasi yang dapat dibaca PostgreSQL dan siapkan sesi operator pemulihan
yang telah diautentikasi; reload hanya setelah validasi rule dan rencana rollback. Dalam lab, backup HBA yang disalin
sebagai root menjadi tidak terbaca PostgreSQL dan rollback gagal sampai pengujian mempertahankan akses user postgres.
Reload yang dilaporkan berhasil saja tidak membuktikan rule aktif; uji koneksi positif/negatif setelah reload.

Pada review awal, `scripts/m1-create-private-database.ps1`, langkah grant ulang dalam `scripts/m1-operator.ps1`/`.sh`,
`scripts/m1-prepare-runtime.ps1`, perintah `psql`/dump/restore di README dan probe yang memakai socket masih
memerlukan penyesuaian; lihat pembaruan operator di bawah. Lab awal belum menguji wrapper Windows terhadap SCRAM, proses
migration/test Laravel, aplikasi multi-tenant, loopback IPv6, koneksi jaringan container lain, atau bootstrap dengan
secret owner lokal yang sebenarnya. Kredensial lab tidak dipakai di server pengguna. Perubahan `pg_hba` aktif,
adaptasi operator, serta prosedur pemulihan nyata memerlukan persetujuan pengguna tersendiri.

**Pembaruan persiapan operator — tidak mengubah HBA aktif.** Script owner untuk pembuatan database privat,
grant setelah migration, probe database test, backup dan restore sekarang membaca secret owner dari mount privat di container database;
password tidak dikirim dalam argumen. Preflight provisioning sebelum override aktif memakai client sementara dengan
mount secret read-only. Test SCRAM sintetis menjalankan script owner dan SQL sebenarnya, termasuk penolakan secret
hilang/salah, target database tidak aman, grant audit, dump/restore, tabrakan nama backup, penolakan restore berulang,
dan pembungkus PowerShell dengan discovery Docker yang disimulasikan.
Penggunaan `PGPASSWORD` di proses sementara masih dapat dilihat oleh administrator host/container atau proses yang
berwenang; ini bukan perlindungan dari akses administratif Docker. Tidak ada perubahan schema atau aturan HBA server aktif.

Backup/restore dengan secret privat sudah diuji end-to-end pada container sintetis, bukan pada server lokal aktif.
Restore memerlukan target privat kosong dan dump tepercaya; pemeriksaan kosong bukan lock untuk mencegah akses paralel,
dan kegagalan restore dapat meninggalkan data parsial. Pemindahan/penyimpanan file backup dari container ke host,
wrapper penuh pembuatan database, alur migration/seed aktual, dan test lintas container pasca-SCRAM pada konfigurasi
lokal juga belum dibuktikan dalam pengujian ini.
Jangan menyimpulkan bahwa seluruh operator siap atau menerapkan SCRAM aktif hanya dari hasil lab ini.

Hasil uji lokal 2026-11-23: lab SCRAM/operator lulus 26 pemeriksaan, helper database privat lulus 27 pemeriksaan,
dan integrasi runtime sintetis lintas tenant lulus 2 test/43 assertion. Pratinjau helper `-WhatIf` tidak membuat
database. Composer valid dan Pint lulus 110 file. Tidak ada migration, backup, restore, perubahan HBA, atau perubahan
data pada server aplikasi aktif dalam pekerjaan ini; suite Laravel penuh tidak dijalankan pada database lokal.

**Review kesiapan SCRAM lokal — 2026-11-23; keputusan penerapan: belum disetujui.** Pemeriksaan read-only ulang
menemukan tujuh rule HBA valid pada file: `trust` untuk socket dan loopback IPv4/IPv6, termasuk tiga rule replikasi,
serta `scram-sha-256` untuk jaringan lainnya. File berada di volume data PostgreSQL, dimiliki `postgres:postgres`
dengan mode `600`; `password_encryption` saat ini SCRAM. Tanpa password, koneksi runtime read-only masih berhasil
melalui socket dan IPv4 loopback. Jenis verifier owner dan runtime keduanya SCRAM; uji owner read-only memakai secret
terpasang secara privat melalui hostname `db` yang ter-resolve ke alamat jaringan non-loopback dan berhasil. Uji
aplikasi read-only berhasil sebagai runtime dan memverifikasi hak audit. Tidak ada password/verifier yang dicetak.

`pg_hba_file_rules` menunjukkan isi file, bukan bukti rule mana yang sudah dimuat; koneksi negatif/positif baru tetap
harus diuji setelah reload. PostgreSQL memilih rule pertama yang cocok; [referensi HBA PostgreSQL 17](https://www.postgresql.org/docs/17/auth-pg-hba-conf.html)
dan [referensi view HBA](https://www.postgresql.org/docs/17/view-pg-hba-file-rules.html). `pg_isready` hanya memeriksa kesiapan server,
bukan kebenaran credential. Perubahan ini tidak mengakhiri atau membuktikan perubahan pada koneksi yang sudah ada.

**Gate sebelum meminta persetujuan penerapan:**

1. Tentukan secara eksplisit perlakuan untuk ketiga rule replikasi setelah inventarisasi client/role replikasi;
   jangan menyalin fixture lab atau mengganti semua rule secara membabi buta. Pastikan rule jaringan dan urutannya tetap benar.
2. Siapkan dan verifikasi salinan konfigurasi HBA asli, pemilik/mode `postgres:postgres`/`600`, serta jalur pemulihan
   yang bisa digunakan jika login baru gagal. Backup database sebelumnya tidak otomatis membuktikan kesiapan rollback HBA.
3. Konfirmasi backup yang dapat dipulihkan, status operator dan credential tanpa mencetaknya, serta waktu penghentian
   pekerjaan yang sesuai. Lab belum membuktikan seluruh wrapper/migration/seed dan pemindahan backup host pada SCRAM live.
4. Rencanakan perubahan minimal dan reload pada container Linux, lalu uji koneksi baru owner/runtime benar dan tanpa
   password, aplikasi/worker/scheduler, hak audit, dan isolasi tenant. Jangan bergantung pada healthcheck saja; jika ada
   kegagalan, hentikan rollout dan jalankan rollback yang sudah direview. Jangan menonaktifkan audit atau menghapus volume.
5. Persetujuan terpisah dari pengguna diperlukan untuk membuat backup konfigurasi aktual, mengubah HBA atau menjalankan
   reload/rollback pada server lokal. Review ini tidak membuat backup baru dan tidak menjalankan tindakan tersebut.

Workflow `.github/workflows/m0-ci.yml` menambahkan lab `m1-scram-synthetic-test.ps1` ke job helper database privat,
dengan image PostgreSQL 17 dan data sintetis. Pada Windows PowerShell, lab lulus 26 dan helper lulus 27 pemeriksaan;
job GitHub Actions/Linux belum dijalankan dan tidak boleh dicatat lulus sebelum ada hasil aktual. File operator dan
fixture sintetis yang belum dilacak Git wajib ikut di-commit agar job dapat berjalan.

### Kandidat aturan replikasi dan persiapan rollback HBA — 2026-11-23

Permintaan pengguna untuk melanjutkan rekomendasi nomor 1 mencakup penentuan aturan replikasi dan penyiapan backup/
rollback. Instalasi kandidat atau reload pada server aktif belum termasuk tahap ini.

Inventarisasi read-only: server bukan standby; `pg_stat_replication`, `pg_replication_slots`, dan `pg_subscription`
masing-masing berjumlah nol. Satu-satunya role dengan superuser/replication adalah owner `lms_user`; tidak ditemukan
konfigurasi client replikasi dalam script, Docker atau workflow repository. `wal_level=replica` dan batas sender/slot
10 adalah kemampuan server, bukan bukti bahwa replikasi digunakan. Client eksternal yang sedang tidak aktif tidak
dapat dibuktikan tidak ada hanya dari snapshot ini; ulangi inventarisasi sebelum pemasangan.

Rancangan operasional: **pertahankan cakupan tiga rule replikasi socket/loopback dan ganti autentikasi menjadi SCRAM**.
Tidak ada perubahan role, `wal_level`, jumlah sender/slot, atau penambahan rule physical replication dari jaringan lain.
`database/security/m1_pg_hba_scram_local.conf` berisi tujuh rule dengan urutan dan cakupan yang sama:

| Koneksi | Database | Alamat | Sebelum | Kandidat |
|---|---|---|---|---|
| Socket | all | — | trust | scram-sha-256 |
| TCP | all | 127.0.0.1/32 | trust | scram-sha-256 |
| TCP | all | ::1/128 | trust | scram-sha-256 |
| Socket | replication | — | trust | scram-sha-256 |
| TCP | replication | 127.0.0.1/32 | trust | scram-sha-256 |
| TCP | replication | ::1/128 | trust | scram-sha-256 |
| TCP | all | all | scram-sha-256 | scram-sha-256 |

Keyword `all` pada kolom database tidak mencakup physical replication; akses replikasi fisik dari alamat lain tetap
tidak ditambahkan. Logical replication memakai aturan koneksi database biasa. Rujukan: [PostgreSQL HBA](https://www.postgresql.org/docs/17/auth-pg-hba-conf.html).

**Snapshot aktual telah dibuat**, tanpa mengubah konfigurasi aktif:

- Lokasi privat di luar repository/mount aplikasi: `../.lms-saas-ops/hba/20261123T144452-2f0f9ae8/`.
- `pg_hba.original.conf` adalah salinan byte-for-byte HBA aktif; SHA-256
  `af0fdb739b67d1a552cd82c19311b813e753d4f651a61e6b563f48b55dca5e74` cocok dengan sumber sebelum dan sesudah penyalinan.
- `pg_hba.proposed.conf`, `m1-hba-rollback.sh`, dan `manifest.json` membekukan kandidat, alat pemulihan, identitas
  container/cluster/volume, dan checksum untuk review. SHA-256 kandidat
  `9af0628899a703be2d8ad8c402a5ccd3662b40606f10ef2d57426e1d0b4423b1`.
- ACL direktori tidak mewarisi akses dan hanya mengizinkan akun operator saat ini, SYSTEM, dan Administrators.
  Snapshot tidak berisi password/database dump. Jangan menyalin isi direktori secret atau menjadikannya mount aplikasi.

**Prosedur rollback untuk digunakan setelah tindakan pemulihan disetujui:**

1. Cocokkan context/endpoint Docker Desktop lokal, ID container, volume `lms-saas-local_postgres_data`, identitas
   cluster dan lokasi HBA dengan manifest. Jika container dibuat ulang atau identitas berubah, hentikan dan review
   ulang; jangan memakai ID/nama yang ditebak. Pastikan tidak ada edit HBA paralel.
2. Verifikasi SHA-256 ketiga file snapshot terhadap manifest. HBA saat ini harus cocok dengan checksum kandidat yang
   hendak dibatalkan. Jika berbeda, periksa perubahan tersebut sebelum memilih checksum yang akan diizinkan;
   script sengaja menolak drift. Jangan mengubah manifest untuk melewati pemeriksaan tanpa review.
3. Salin hanya `pg_hba.original.conf` dengan `docker cp` ke nama staging baru dan belum ada, misalnya
   `/tmp/lms_hba_recovery_<id_unik>.conf` pada container yang sudah diverifikasi. Setelah menyalin, gunakan akses
   administratif Docker untuk memberi owner `postgres:postgres` dan mode `600` pada **file staging itu saja**.
   Jangan menyalin langsung ke HBA aktif dan jangan mengubah permission direktori data secara rekursif.
4. Jalankan isi script rollback yang checksum-nya telah diverifikasi menggunakan `docker exec -u postgres ... sh -c`,
   dengan argumen setelah `--`: path staging, SHA-256 asli, SHA-256 HBA kandidat. Script memeriksa semua argumen dan
   checksum, membuat file sementara unik di direktori data, memasang salinan secara atomik dengan owner/mode yang
   benar, lalu menjalankan `pg_ctl -D /var/lib/postgresql/data reload`. Jalur ini tidak memerlukan login database.
5. Sukses mengirim sinyal reload bukan bukti autentikasi sudah berubah. Bandingkan SHA-256, owner/mode file, rule/error
   HBA, koneksi baru dan koneksi runtime aplikasi; tinjau log server secara privat. Setelah hasil terverifikasi, hapus
   hanya staging yang dibuat operasi ini. Simpan snapshot asli. Rollback mengembalikan `trust` lama untuk pemulihan
   sementara, sehingga akses Docker tetap administratif dan perbaikan SCRAM harus dijadwalkan ulang.

Lab sekarang memakai kandidat tujuh rule yang sama. Hasil: **33 pemeriksaan lulus**, termasuk IPv4/IPv6, physical
replication dengan password benar/salah/tidak ada, penolakan runtime untuk replikasi, batas rule remote replication,
checksum rollback yang salah/drift, pemulihan byte asli dan mode `600`, serta reload tanpa credential database.
Seluruh perubahan HBA/reload untuk pengujian terjadi pada PostgreSQL sintetis. Tidak ada instalasi kandidat,
reload, migration atau perubahan data aplikasi pada server aktif; persetujuan penerapan masih terbuka.

Latihan tambahan dengan `-HbaSnapshotPath ../.lms-saas-ops/hba/20261123T144452-2f0f9ae8/pg_hba.original.conf`
lulus **34 pemeriksaan**: salinan konfigurasi lokal yang asli dipakai sebagai baseline pada server sintetis, lalu
kandidat diuji dan dikembalikan ke checksum snapshot tersebut. Opsi ini hanya mengirim file konfigurasi ke container
sementara; default CI tetap memakai baseline sintetis dan tidak memerlukan file privat lokal. Verifikasi seluruh
checksum manifest dan ACL snapshot lulus dengan identitas operator yang berwenang. SHA-256 HBA aktif tetap sama.
Regresi integrasi runtime sintetis lintas tenant lulus 2 test/43 assertion; ini tidak membuktikan perubahan HBA pada
aplikasi aktif. Container latihan dibersihkan setelah selesai.

### Penerapan HBA SCRAM lokal — disetujui dan diverifikasi 2026-11-23

Pengguna menyetujui pertanyaan pemasangan kandidat dan reload dengan jawaban "oke saya setujui". Persetujuan mencakup
perubahan autentikasi lokal dan pemulihan jika verifikasi gagal; tidak mencakup perubahan schema, data, Git push,
atau deployment produksi. Catatan "belum disetujui/belum dipasang" pada bagian sebelumnya adalah riwayat persiapan.

Preflight mencocokkan context/endpoint Docker Desktop, container, cluster, volume, lokasi HBA, checksum snapshot dan
kandidat, verifier SCRAM kedua role, serta inventarisasi replikasi yang masih nol. Credential owner dan runtime
diverifikasi melalui jaringan SCRAM sebelum pemasangan. HTTP baseline lulus enam pemeriksaan.

File terpasang secara atomik pada `/var/lib/postgresql/data/pg_hba.conf`, tetap dimiliki `postgres:postgres` dengan
mode `600`, kemudian reload diminta melalui `pg_ctl`. SHA-256 aktif cocok dengan kandidat yang disetujui:
`9af0628899a703be2d8ad8c402a5ccd3662b40606f10ef2d57426e1d0b4423b1`. Ketujuh rule menggunakan `scram-sha-256`;
tidak ada perluasan alamat atau hak replikasi, pergantian role/password, restart service, atau perubahan Docker M0.

**Hasil aktual: 54 pemeriksaan rollout lulus.** Koneksi baru owner berhasil melalui socket, IPv4 dan IPv6; koneksi
owner/runtime dengan password salah atau tanpa password ditolak pada ketiganya. Runtime dengan secret privat yang
benar berhasil pada IPv4/IPv6. Physical replication owner dengan password berhasil, tanpa password ditolak, dan
runtime tetap tidak berhak replikasi. Semua rule ter-parse tanpa error. Koneksi runtime baru dan hak audit lulus pada
app, worker dan scheduler; HTTP central, tiga tenant, unknown host dan loopback tetap lulus enam pemeriksaan.

Jumlah tenant/user/migration sebelum dan sesudah tetap **3/4/5**. Pemeriksaan tambahan wrapper operator PowerShell
sebenarnya pada server aktif lulus dengan query read-only. Tidak ada migration, seed, DDL/grant, restore database,
atau perubahan data siswa. Password tidak dicetak atau disalin ke argumen/log. Semua layanan tetap berjalan;
rollback tidak diperlukan. Dua file staging operasi ini sudah dihapus; snapshot asli tetap tersedia untuk pemulihan.

Bukti privat di `../.lms-saas-ops/hba/20261123T144452-2f0f9ae8/`:

- `apply-reviewed.ps1`: operasi pemasangan lokal yang memakai routine penggantian atomik teruji dan memulihkan HBA
  asli bila pemeriksaan kritis gagal. Skrip ini terikat snapshot/container awal dan bukan installer umum.
- `rollout-77ce8774306444ee95dea5be5564be0e.json`: preflight read-only sebelum pemasangan.
- `rollout-6345cd195a4c4d30a47e5265ae0179f9.json`: hasil `verified-applied`, 54 pemeriksaan,
  2026-11-23 08:02:11–08:03:19 UTC; checksum, jumlah baris, dan pembersihan staging tercatat.

Manifest snapshot tetap menyimpan status persiapan awal yang tidak ditimpa; record rollout di atas adalah bukti
penerapan terbaru. Batas pengujian: sesi worker lama tidak diputus; pemeriksaan memakai koneksi baru. Workflow
migration/seed dan backup/restore penuh pada server aktif belum dijalankan ulang. Volume baru/instalasi M0 lain tidak
otomatis memakai HBA ini. Status produksi, RLS, review PR6 dan bukti GitHub Actions tetap mengikuti gate masing-masing.

Verifikasi penutup setelah rollout: regresi runtime lintas tenant pada server sintetis lulus **2 test/43 assertion**;
tidak ditemukan indikator error pemuatan HBA dalam log periode rollout yang diperiksa secara privat. Checksum HBA
aktif tetap cocok dengan kandidat. Pemeriksaan log tidak mencetak isi log, query, atau credential.

```text
Audit database hardening design: [x] Approved — reviewer: user (chat approval), date: 2026-11-21
Implementation: local rollout verified — not production approval; PR6 remains under review.
```

## Riwayat final review PR6 / penutupan M1 — 24 November 2026

Pengguna meminta final review dan penutupan M1. Review dilakukan terhadap commit
`b07ebdf182080f184065caf5b60a2b81c9efafaa`; ini bukan persetujuan otomatis PR6 atau perubahan baseline arsitektur.
Laporan lengkap: [M1_FINAL_REVIEW.md](M1_FINAL_REVIEW.md). Checklist dan matrix sekarang mencatat evidence,
termasuk poin parsial/terbuka; catatan persiapan serta insiden historis di atas tetap dipertahankan.

Bukti aktual pada 24 November 2026:

- Operator `test` pada `lms_saas_test`: **75 test/513 assertion lulus**, 324,09 detik, termasuk concurrency.
- Integrasi runtime PostgreSQL tmpfs terisolasi: **2 test/43 assertion lulus**; container/jaringan latihan dibersihkan.
- Composer valid; Pint **110 file lulus**; JavaScript recovery **3 test lulus**; HTTP host smoke **6 pemeriksaan lulus**.
- Route inventory: 45 route aplikasi; lima migration lokal `Ran`; koneksi/hak audit runtime app, worker, scheduler lulus.
- [GitHub Actions run 45137773386](https://github.com/Alfdhiw/LMS_SaaS/actions/runs/45137773386)
  untuk commit yang direview: job `private-database-helper` dan `smoke` sukses, termasuk lab SCRAM.
  Hanya metadata status job/step yang dibaca; log CI tidak diunduh.
- Hitungan database utama pada baseline, counter-check dan pembacaan penutup tetap 3 tenant aktif, 4 user, 5 migration, 14 audit platform,
  dan 5 audit tenant. Katalog memiliki 17 tabel dan 95 constraint tervalidasi; tujuh rule HBA terbaca sebagai SCRAM.
  Hitungan ini bukan fingerprint seluruh isi data atau bukti seluruh constraint ERD sudah diuji negatif.

Temuan yang menghalangi penutupan:

1. **High — AUTH-06:** alur user aktif mengganti password dengan sandi lama/re-auth belum tersedia; recovery
   email/sekolah bukan pengganti requirement tersebut. Tidak ditemukan route/controller/action/test perubahan mandiri.
2. **High — ADR-002:** provisioning/lifecycle/resend masih menulis langsung tabel milik Tenancy/Identity dari
   Platform; Support mengimpor tipe dari Modules. Belum ada pengecualian arsitektur yang disetujui untuk drift ini.
3. **High — evidence:** suite hijau tidak membuktikan semua matrix. Fault injection kegagalan invitation di tengah
   provisioning, kegagalan pengiriman email, dan job A -> B belum memiliki tes langsung; beberapa skenario session
   dan constraint baru teruji parsial. Rincian ada pada matrix.
4. **Medium — browser/HTTPS:** percobaan menghubungkan browser terblokir error alat `missing field sandboxPolicy`.
   Tidak ada login/demo browser yang dijalankan; HTTP smoke dan test JavaScript bukan bukti browser HTTPS.

Tidak ada implementasi perbaikan/pengecualian arsitektur, perubahan schema, migration/seed database utama, restore,
perubahan HBA/role/password, konfigurasi Docker, atau data siswa nyata pada review ini. Tes menjalankan migration
hanya pada database test khusus dan PostgreSQL sementara. Tidak ada commit/push/merge atau pengerjaan M2.

```text
PR6 final technical review: changes requested — date: 2026-11-24
PR6 approval: [ ] Approved — menunggu penyelesaian temuan dan keputusan pengguna
M1 closure: [ ] Complete — acceptance evidence belum lengkap
Production / real-student pilot: NOT APPROVED
```

## Persetujuan remediasi final review 1–3 — 24 November 2026

Pengguna menyetujui rekomendasi implementasi dengan jawaban "oke saya setujui": (1) perubahan password mandiri,
(2) perbaikan write boundary/arah dependency sesuai ADR-002, dan (3) perluasan pengujian keamanan/session/job.
Persetujuan ini bukan approval hasil PR6/M1, nomor 4 demonstrasi/setup HTTPS, produksi, M2, atau Git push/merge.

Remediasi sudah diimplementasikan mengikuti baseline Accepted tanpa pengecualian arsitektur atau perubahan schema:

- Alur authenticated `/password/change` tenant/central memverifikasi password lama, membatasi input sesuai hasher,
  mengaudit secara atomik, mencabut recovery aktif dan sesi global. MFA, CSRF, rate limit dan transport tetap dijaga.
- Platform mengorkestrasi transaksi melalui contract/action owner Tenancy/Identity. Support tidak lagi mengimpor
  Modules; permission mapping platform, provider dan seluruh consumer namespace diselaraskan.
- Feature tests langsung mencakup rollback invitation di tengah bundle, mail failure/resend, collision/XOR/expiry,
  replay sesi setelah suspend/role/reset lintas host. Architecture guard dan queue sync exception/context tests tersedia.
- Full regression final lulus **100 test/791 assertion**, 311,85 detik. Runtime sintetis lulus 2 test/51 assertion;
  Pint 134 file, Composer valid, JavaScript 3 test dan HTTP smoke 6 pemeriksaan lulus. Evidence dan inventory lengkap
  ada pada §8 `M1_FINAL_REVIEW.md`.
- Snapshot read-only database utama tetap 3 tenant aktif/4 user/5 migration/14 audit platform/5 audit tenant;
  95 constraint tervalidasi dan 7 rule SCRAM tanpa error. Tidak ada migration/seed/rebuild/restore utama,
  perubahan role/HBA/password pilot, konfigurasi Docker, data nyata, commit/push/merge atau pekerjaan M2.

Catatan temuan F01–F03 di bagian review awal merupakan riwayat sebelum remediasi, bukan status implementasi terbaru.
Browser/HTTPS F04 dan CI commit remediasi belum mempunyai evidence baru; CI `b07ebdf` tetap bukti historis saja.

```text
Remediation scope 1–3: [x] Approved — reviewer: user (chat), date: 2026-11-24
PR6 result / M1 closure: [ ] Approved — evidence browser/HTTPS + CI commit baru + keputusan akhir belum lengkap
M2 / production / real-student pilot: NOT AUTHORIZED
```

## Approval akhir PR6/M1 — 25 November 2026

Sumber keputusan manusia: pengguna menyatakan **"saya approval akhir PR6/M1, tolong kamu perbarui semua"**.
Reviewer/pemberi persetujuan: pengguna/pemilik proyek melalui percakapan, tanggal 2026-11-25 (Asia/Jakarta).
Ini berbeda dari persetujuan remediasi 1–3 pada 24 November dan bukan approval yang diberikan Codex sendiri.

```text
PR6 result: [x] Approved — reviewer: user (explicit final chat approval), date: 2026-11-25
M1 closure: [x] Closed by owner — local development / synthetic data
Approved code: Milestone-1 / 015e792f350536db14f4be049624e6d0e82e95b2
Acceptance evidence: [ ] Complete — browser four-host / HTTPS demonstration remains unverified
Production / real-student pilot / M2 / automatic commit-push-merge-release: NOT AUTHORIZED
```

Evidence yang tersedia saat pencatatan:

- Remediasi F01–F03 sudah di-commit sebagai `015e792`. Git working tree bersih sebelum pembaruan dokumentasi ini.
- [M0 checks run 45356905502](https://github.com/Alfdhiw/LMS_SaaS/actions/runs/45356905502) untuk SHA di atas:
  workflow success, job `smoke` dan `private-database-helper` success. Langkah Pint, All tests, helper privat dan
  lab SCRAM sukses. Workflow selesai 24 November 2026 pukul 15:34:43 WIB; metadata dibaca 25 November tanpa token/log.
- Hasil lokal 24 November tetap 100 test/791 assertion, runtime 2 test/51 assertion, Pint 134 file, JavaScript
  3 test, dan HTTP smoke 6 pemeriksaan lulus. Bukan pengujian yang diulang pada tugas dokumentasi 25 November.

Approval milestone tidak membuktikan F04 browser/HTTPS telah diuji dan tidak menghapus syarat acceptance maupun
insiden PR5. Follow-up browser/HTTPS, konsistensi validasi password lintas activation/recovery, dan gate produksi
tetap tercatat pada §9 [M1_FINAL_REVIEW.md](M1_FINAL_REVIEW.md). Tidak ada waiver atau keputusan arsitektur baru.
Dokumentasi approval ini belum di-commit/push; CI kode tidak otomatis membuktikan perubahan dokumentasi berikutnya.
Tidak ada kode, schema/migration, data, role/HBA, Docker, credential atau permission runtime yang diubah.

## Bukti browser publik HTTP — 25 November 2026

Permintaan pengguna untuk menyediakan bukti browser ditindaklanjuti melalui Chrome lokal terpasang setelah
browser bawaan gagal sebelum navigasi (`missing field sandboxPolicy`, dua percobaan). Container LMS yang berhenti
24 jam sebelumnya dinyalakan dengan `docker compose start`; volume lama dipertahankan, tanpa recreate/migration/seed.
Enam HTTP host smoke lulus. Sebelas kasus browser publik/tamu lulus dan mempunyai screenshot serta manifest SHA-256;
detail: §10 [M1_FINAL_REVIEW.md](M1_FINAL_REVIEW.md). Tidak memasukkan password/OTP atau mengirim form apa pun.

Status evidence: **PASS_PUBLIC_HTTP_ONLY**. Ini bukan bukti sesi terautentikasi/HTTPS. Tidak ada sertifikat atau trust
Windows yang diubah; permintaan izin sertifikat lokal masih menunggu jawaban pengguna. Tidak ada code/schema/Docker
M0, role/grant, credential pilot, keputusan arsitektur atau status approval yang diubah. Pengecualian Git dibatasi
ke screenshot publik dan manifest; raw DOM/header/cookie, certificate/key dan profil tidak dijadikan artifact.

Setelah perubahan keputusan, update status di dokumen asli dan `docs/DOCUMENT_INDEX.md`; simpan riwayat, jangan hapus versi lama. Draft migration disesuaikan jika review mengubah schema.
