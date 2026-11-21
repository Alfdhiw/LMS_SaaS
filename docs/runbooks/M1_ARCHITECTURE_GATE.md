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

## Hardening audit PostgreSQL — rancangan disetujui, implementasi belum selesai

Pada pemeriksaan lokal, koneksi aplikasi memakai `lms_user`; role tersebut adalah **superuser** dan pemilik kedua tabel
audit. Karena itu `REVOKE` dari role tersebut tidak menghasilkan batas keamanan yang efektif. Tabel audit saat ini
belum dijamin append-only di level hak PostgreSQL. Baseline ADR-001 mengarahkan role request non-superuser/non-owner;
rancangan penerapan di bawah disetujui pengguna melalui percakapan pada 2026-11-21, tetapi **belum diterapkan** dan
tidak mengubah status approval PR6.

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
aplikasi tidak menjadi target dan role runtime aktual belum dibatasi.

**Persiapan penerapan:** backup lokal baru diverifikasi dengan checksum. Skrip
`database/security/m1_audit_runtime_grants.sql` dan template `docker/compose.audit-local.override.yml` disiapkan;
`tests/Support/m1_audit_runtime_grants_test.ps1` menguji grant dengan role sintetis pada `lms_saas_test` dan rollback.
Uji positif `SELECT`/`INSERT`, penolakan `UPDATE`/`DELETE`/`TRUNCATE`/`ALTER`, akses sequence, dan penolakan pembuatan
objek schema lulus. Guard menolak target `postgres`. Template Docker lulus `config --quiet` tanpa diterapkan.
Skrip grant saat ini memberikan DML untuk tabel aplikasi non-audit yang sudah ada dan bukan bukti least-privilege
per modul; tidak ada default grant untuk tabel baru, sehingga proses deployment harus memperbarui dan menguji grant
setelah migration sebelum dialihkan ke runtime.
Pemeriksaan keamanan alat menolak langkah pemindahan kredensial owner karena berpotensi menampilkan password dalam
output; kredensial belum dipindahkan, role runtime belum dibuat, grant belum diterapkan, `.env` dan koneksi aplikasi
belum diubah. Pemilik harus menyiapkan kredensial secara privat tanpa memberikannya melalui chat sebelum rollout
diteruskan. File sementara backup di container dibersihkan setelah salinan Windows diverifikasi.

```text
Audit database hardening design: [x] Approved — reviewer: user (chat approval), date: 2026-11-21
Implementation: incomplete — role runtime dan konfigurasi koneksi belum diterapkan.
```

Setelah perubahan keputusan, update status di dokumen asli dan `docs/DOCUMENT_INDEX.md`; simpan riwayat, jangan hapus versi lama. Draft migration disesuaikan jika review mengubah schema.
