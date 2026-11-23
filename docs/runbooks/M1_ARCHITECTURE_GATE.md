# M1 ARCHITECTURE GATE — Persetujuan sebelum migration final

## Status review saat ini

- `docs/adr/ADR_001_Multi_Tenancy_Identity_Architecture_v1.0.md`: **Accepted**
- `docs/adr/ADR_002_Application_Architecture_Modular_Structure_v1.0.md`: **Accepted**
- `docs/erd/ERD_Core_LMS_SaaS_v1.1.md`: **Accepted**

Baseline arsitektur dan ERD di atas telah disetujui oleh pengguna pada 8 November 2026. Catatan ini menyelaraskan repository dengan approval tersebut; ini bukan persetujuan otomatis oleh Codex. Risiko produksi yang masih terbuka tetap harus ditangani melalui milestone dan gate terkait.

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

## PR6 — bukti implementasi untuk review

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

Khususnya `scripts/m1-create-private-database.ps1`, langkah grant ulang dalam `scripts/m1-operator.ps1`/`.sh`,
`scripts/m1-prepare-runtime.ps1`, perintah `psql`/dump/restore di README dan probe yang memakai socket saat ini masih
perlu penyesuaian dan pengujian sebelum perubahan live. Lab belum menguji wrapper Windows terhadap SCRAM, proses
migration/test Laravel, aplikasi multi-tenant, loopback IPv6, koneksi jaringan container lain, atau bootstrap dengan
secret owner lokal yang sebenarnya. Kredensial lab tidak dipakai di server pengguna. Perubahan `pg_hba` aktif,
adaptasi operator, serta prosedur pemulihan nyata memerlukan persetujuan pengguna tersendiri.

Workflow `.github/workflows/m0-ci.yml` kini menambahkan job terpisah untuk `m1-private-database-test.ps1` dengan
image PostgreSQL 17 dan data sintetis. Uji tersebut lulus lokal 27 pemeriksaan pada Windows PowerShell; job pada
GitHub Actions/Linux belum dijalankan dan tidak boleh dicatat lulus sebelum ada hasil aktual.

```text
Audit database hardening design: [x] Approved — reviewer: user (chat approval), date: 2026-11-21
Implementation: local rollout verified — not production approval; PR6 remains under review.
```

Setelah perubahan keputusan, update status di dokumen asli dan `docs/DOCUMENT_INDEX.md`; simpan riwayat, jangan hapus versi lama. Draft migration disesuaikan jika review mengubah schema.
