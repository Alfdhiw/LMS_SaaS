# M1 FINAL REVIEW — 24 November 2026

| Metadata | Hasil |
|---|---|
| Scope | Final review PR6 dan kesiapan penutupan M1, bukan implementasi M2 |
| Branch / commit kode | `Milestone-1` / `b07ebdf182080f184065caf5b60a2b81c9efafaa` |
| Status technical review | Remediasi F01–F03 diimplementasikan; bukti penutupan belum lengkap (§8) |
| Approval PR6 / M1 | Belum diberikan; permintaan melakukan review bukan approval hasil review |
| Produksi / data siswa nyata | Tidak disetujui |
| Lingkungan | Docker Desktop Linux lokal, Laravel 13.40.0, PHP 8.4.27, PostgreSQL 17.11 |

**Status terbaru:** §8 mencatat remediasi yang telah diizinkan pengguna setelah review awal. Bagian 1–7 di bawah
tetap menjadi snapshot commit `b07ebdf` sebelum remediasi, bukan klaim fitur/test tersebut masih belum tersedia.
Persetujuan implementasi bukan approval akhir PR6/M1. Working tree baru belum memiliki commit/CI sendiri.

## 1. Kesimpulan

Implementasi M1 yang tersedia lulus suite otomatis existing dan CI commit yang direview. Namun, **M1 belum dapat
ditutup**: ada requirement P0 yang belum tersedia, ketidaksesuaian boundary ADR-002, coverage matrix yang parsial,
serta bukti browser/HTTPS yang belum tersedia. Review tidak mengubah requirement, baseline arsitektur, atau status
approval agar milestone terlihat selesai. Approval PR0–PR5 yang sudah dicatat tetap merupakan riwayat yang berlaku.

## 2. Acuan dan batas review

Acuan M1: `AGENTS.md`, `DOCUMENT_INDEX.md`, PRD Master v1.0, ADR-001/002 v1.0, ERD Core v1.1, PRD Authentication/RBAC
v1.0, PRD SaaS Central v1.0, master plan, acceptance checklist, test matrix, architecture gate, source, migrations,
tests dan runbook operasional. Status baseline PRD/ADR/ERD adalah **Accepted** menurut indeks/approval record;
review membaca kontrak M1 yang relevan dan tidak merupakan audit penuh modul M2–M6 atau sertifikasi keamanan.

Requirement yang diperiksa mencakup host/session isolation, AUTH-01–09/11–13 sesuai scope M1, SAAS-01–05/10–11,
provisioning/activation/recovery, permission platform terpisah, audit, serta kontrol operasional lokal.
Policy course/file/grade nyata belum ada di M1; tidak diasumsikan selesai hanya karena route tersebut tidak tersedia.

## 3. Pengujian yang benar-benar dijalankan

Perintah dari root `M0`, pada 24 November 2026. Tidak menjalankan `setup`, migration/seed, rollback, atau restore
pada database utama. Migration milik test hanya berjalan pada `lms_saas_test`/PostgreSQL sementara.

| Perintah / pemeriksaan | Hasil aktual | Batas |
|---|---|---|
| `powershell -NoProfile -File scripts/m1-operator.ps1 test` | **75 test, 513 assertion lulus**, 324,09 detik | Database khusus `lms_saas_test`, owner operator sementara |
| `powershell -NoProfile -File tests/Support/m1-runtime-integration.ps1` | **2 test, 43 assertion lulus** | PostgreSQL tmpfs + jaringan internal unik; fixture sintetis; resource latihan dibersihkan |
| `docker compose exec -T app composer validate --no-check-publish` | Valid | Bukan dependency vulnerability audit |
| `docker compose exec -T app ./vendor/bin/pint --test` | **110 file lulus** | Tidak memformat/mengubah kode |
| `node --test tests/Support/school_recovery_ui.test.js` | **3 test lulus** | DOM/crypto simulasi; bukan browser end-to-end |
| `powershell -NoProfile -File scripts/m1-host-smoke.ps1` | **6 pemeriksaan lulus** | GET login central/3 sekolah, unknown host dan loopback; tanpa login/mutasi |
| `docker compose exec -T app php artisan route:list -v --except-vendor` | **45 route**, middleware terinventarisasi | Tidak ada route perubahan password mandiri |
| `docker compose exec -T app php artisan migrate:status` | **5 migration Ran** | Read-only; bukan menjalankan migration utama |
| `docker compose exec -T <app/worker/scheduler> php scripts/m1-check-runtime.php` | Ketiga service lulus koneksi/hak audit runtime | Query read-only, tidak menulis audit atau meniru seluruh job |
| `docker compose config --quiet` | Lulus | Tidak mencetak Compose yang sudah berisi interpolasi secret |
| Snapshot konfigurasi sesi via Tinker, hanya empat nilai non-rahasia | `host_only=true`, driver `file`, `secure=false`, SameSite `lax` | Konfigurasi lokal HTTP, bukan bukti cookie browser/konfigurasi produksi |
| Owner wrapper dengan SQL `BEGIN READ ONLY` | Baseline/counter-check dan katalog lulus | Secret owner hanya dibaca di container, bukan dicetak atau dikirim di argv |

`M1DatabaseContractTest` (8 test), M0 smoke (3 test), Identity concurrency (6 test), Provisioning concurrency
(1 test), serta dashboard (9 test) termasuk dalam **75 test**, bukan jumlah tambahan.

### CI terverifikasi

[M0 checks run 45137773386](https://github.com/Alfdhiw/LMS_SaaS/actions/runs/45137773386) untuk commit di atas:

- Job `private-database-helper`: **success**, termasuk helper database privat dan lab SCRAM sintetis.
- Job `smoke`: **success**, termasuk setup runner, Pint dan seluruh test yang dikonfigurasi.
- Workflow selesai 23 November 2026 pukul 15:23:10 WIB; metadata status diperiksa 24 November 2026 melalui API GitHub.

Pembacaan API awal gagal saat negosiasi jaringan; pengulangan dengan TLS 1.2 berhasil. Tidak memakai credential
GitHub, mengunduh log/artifact, menjalankan ulang workflow, atau melakukan push. CI ini bukan bukti penerapan role
runtime/SCRAM pada instalasi baru; runtime integration dan JavaScript di atas bukan langkah CI default.
Perubahan dokumentasi review ini belum di-commit/push dan belum mempunyai run CI sendiri.
Pemeriksaan akhir link Markdown lokal, whitespace (termasuk tiga file bukti baru), `git diff --check`, serta aturan
ignore lulus. Tidak ada resource container/network `lms-runtime-test-*` tertinggal setelah integrasi runtime.

## 4. Temuan yang harus diselesaikan

### F01 — High: perubahan password mandiri AUTH-06 belum tersedia

PRD Authentication/RBAC §5.2 mewajibkan user aktif mengganti password dengan sandi lama/re-auth, hash password
global, audit dan pencabutan sesi. Inventory 45 route hanya memiliki forgot/reset dan recovery berbantuan;
`routes/tenant.php`, `routes/central.php`, controller dan service Identity belum mempunyai alur authenticated
password change. Pencarian `current_password`/change-password juga tidak menemukan implementasi atau test terkait.

Recovery yang tersedia tidak menggantikan fitur ini: pengguna harus berpindah ke proses lupa sandi/verifikasi
pihak lain, sedangkan akun platform ditolak oleh recovery email sekolah. Dampaknya fungsional dan keamanan akun;
ini bukan bukti credential saat ini telah bocor. `must_change_password` tidak mempunyai enforcement runtime;
fallback password sementara **tidak boleh dipakai** sebelum flow/enforcement dan pengujiannya tersedia.

Tindak lanjut yang diusulkan: perubahan password milik sendiri di Identity, re-auth, CSRF/rate limit, audit atomik,
increment `users.auth_version`, revokasi lintas host, UI dan tes positif/negatif. Jangan menambah hak reset global
ke admin sekolah. Implementasi menunggu persetujuan pengguna.

### F02 — High: boundary ADR-002 belum dipenuhi

- `app/Modules/Platform/Services/TenantProvisioningService.php:116` dan seterusnya langsung membuat `tenants`,
  domains/settings, users, membership/roles dan invitations milik Tenancy/Identity.
- `app/Modules/Platform/Services/TenantLifecycleService.php:65` langsung memperbarui tenant dan pada baris 73
  mengubah version membership; `TenantInvitationDeliveryService.php:110` merotasi invitation milik Identity.
- `app/Support/Tenancy/TenantContextStore.php:5`, `app/Support/Auth/AuthSessionManager.php:5` dan
  `app/Support/Audit/AuthAuditLogger.php:5` bergantung pada tipe/utility di Modules, berlawanan dengan arah
  dependensi Support menurut ADR-002 §7. README Contracts masih placeholder, bukan pengecualian yang disetujui.

Transaksi dan tes fungsional saat ini tetap berjalan; temuan ini **bukan bukti kebocoran tenant**. Namun, consumer
write dapat melewati invariant pemilik modul dan dependency ini mempersulit pengembangan M2. Pindahkan write
ke action/contract pemilik, tetap pertahankan transaksi atomik/idempotency/audit; tambahkan architecture tests.
Tidak mengarang pengecualian ADR atau melakukan refactor otomatis pada tahap review ini.

### F03 — High: suite hijau belum membuktikan seluruh matrix wajib

Pemetaan lengkap: [M1_TEST_MATRIX.md](M1_TEST_MATRIX.md). Contoh gap yang konkret:

- **M1-PROV04:** test bundle bernama atomic memeriksa happy path. Collision slug ditolak sebelum insert bundle;
  belum ada fault injection invitation setelah tenant/domain/settings berhasil dibuat beserta assertion rollback semuanya.
- **M1-PROV05:** mail fake/resend sukses diuji, tetapi kegagalan mail transport dan pemulihan resend belum diuji langsung.
- **M1-SEC04:** context antar-request HTTP diuji; job A -> B, termasuk exception/failure, belum diuji.
- **M1-AUTH03/04/06:** beberapa status/revocation hanya memeriksa version, GET login atau replay host A, belum
  seluruh protected session/status/dua host sebagaimana expected matrix.
- Constraint XOR creator ganda, domain/reserved collision dan expiry undangan anggota biasa masih parsial.

Ketiadaan test bukan bukti implementasinya salah, tetapi mencegah klaim seluruh acceptance matrix lulus.
Jangan menurunkan expected result atau menandai skenario N/A tanpa alasan scope dan keputusan yang dicatat.

### F04 — Medium: demonstrasi browser/HTTPS belum dapat dibuktikan

Browser yang disediakan sudah dicoba; panggilan gagal sebelum koneksi dengan `missing field sandboxPolicy`.
Tidak ada navigasi, login, screenshot, pengiriman form, atau perubahan credential melalui browser pada review ini.
Docker web yang diperiksa memublikasikan HTTP `127.0.0.1:8080`; setup TLS/certificate/trusted proxy tidak diubah.

Enam GET smoke dan test JavaScript tidak membuktikan cookie browser empat host, MFA/recovery dua sesi atau secure
context HTTPS. Perlu alat browser yang berfungsi atau demonstrasi pengguna dengan bukti tersanitasi; jika butuh
TLS lokal, siapkan langkah terpisah yang disetujui. Jangan menonaktifkan pemeriksaan TLS atau mengirim password/token
ke chat. Ini bukan kewajiban hosting publik.

## 5. Integritas data dan batas operasional

Pembacaan baseline, counter-check dan penutup read-only `lms_saas` menunjukkan: **3 tenant aktif, 4 user, 5 migration, 14 audit platform,
5 audit tenant**. Ada 17 tabel publik, 95 constraint tervalidasi (30 CHECK, 30 FK, 17 PK, 18 UNIQUE), tidak ada
permission platform pada role sekolah, dan tidak ada role selain empat role sekolah dalam katalog.
Tujuh aturan HBA terbaca sebagai SCRAM; tidak ada perubahan konfigurasi/autentikasi PostgreSQL dalam review ini.

Hitungan baris/katalog bukan fingerprint isi atau pembuktian exhaustive setiap constraint ERD. Query tidak
mengambil nama siswa, credential, hash verifier, isi invitation/recovery, atau metadata audit. Tidak ada migration,
seed, restore, perubahan role/HBA/password atau data siswa pada database utama. GET smoke dapat membuat sesi web
normal; tidak mengubah state bisnis sekolah. Test suite boleh membuat/menghapus fixture hanya di database test.

Insiden PR5 19 November dan rebuild lokal dengan izin pengguna tetap tercatat pada architecture gate; hasil review
tidak mengembalikan data yang hilang dan tidak membenarkan centang historis "tidak pernah menghapus data M0".
Restore drill 21 November adalah bukti historis. Restore end-to-end app, secret/APP_KEY/MFA, role/grant setelah
hardening, Redis/files, backup offsite/enkripsi, RPO/RTO dan beban/staging tidak diuji ulang di sini.

## 6. Dampak perubahan review

- Perubahan hanya dokumentasi dan tiga pengecualian `.gitignore` agar checklist/matrix/laporan dapat dilacak.
  Dokumen lain tetap di-ignore; `.env`, secret, dump dan backup tidak dibuka untuk Git.
- README, CHANGELOG, indeks dan gate merujuk status review yang sama. AGENTS lokal dan README Contracts masih
  mempunyai wording/placeholder lama; sinkronisasi menyeluruh belum diklaim selesai.
- Tidak ada perubahan controller/service/model/API/permission, schema/migration, dependency, Docker M0, role atau HBA.
  Perbaikan F01–F03 akan berdampak pada Identity, Platform, Tenancy, Support, UI dan regression tests; belum dilaksanakan.
- Risiko produksi RLS/alternatif isolasi DB, outbox/durable delivery, retensi/redaction, backup/MFA, session/TLS
  dan privasi tetap mengikuti gate produksi. Tidak semuanya harus diimplementasikan untuk M1 lokal, tetapi tidak
  boleh dianggap telah selesai atau menjadi izin data nyata. Tidak ada keputusan penundaan baru oleh reviewer.

## 7. Syarat penutupan dan approval

1. Selesaikan gap requirement/boundary dan bukti matrix yang applicable, dengan approval scope implementasi dari pengguna.
   Jika mengubah baseline, proses ADR/PRD/ERD yang berlaku wajib diikuti; reviewer tidak memberi waiver sendiri.
2. Ulangi tes yang terdampak, full regression, style dan CI pada commit hasil perbaikan.
3. Lengkapi demonstrasi browser empat host/HTTPS sesuai acceptance, catatan historis insiden dan sinkronisasi dokumen.
4. Minta keputusan eksplisit pengguna atas PR6/M1 setelah evidence lengkap, lalu catat di gate/indeks.

```text
PR6: [ ] Approved   [x] Technical changes requested
M1:  [ ] Complete  [x] Acceptance evidence incomplete
M2:  [ ] Authorized / started
```

Tidak ada commit/push/merge, approval produksi, atau pekerjaan M2 dalam review ini.
Saran commit: `docs: record M1 final review findings and acceptance evidence`.

## 8. Remediasi F01–F03 yang disetujui pengguna — 24 November 2026

### 8.1 Otorisasi dan keputusan

Setelah laporan review, pengguna menjawab "oke saya setujui" untuk implementasi rekomendasi 1–3: perubahan password
mandiri, pemulihan boundary ADR-002, dan test negatif/session/job. Ini bukan persetujuan untuk PR6/M1 final,
demonstrasi/setup HTTPS, M2, produksi, Git push atau merge. Baseline Accepted PRD/ADR/ERD tidak diubah dan tidak
diberi waiver. Branch tetap `Milestone-1`; HEAD masih baseline review sampai pengguna melakukan commit.

### 8.2 Perubahan dan dampak lintas project

- **F01:** `ChangeOwnPassword`, FormRequest/controller/form Blade dan empat route GET/POST tenant/central.
  Identitas/tenant hanya dari sesi server dan host terverifikasi; payload target ditolak. Password lama diverifikasi,
  password baru dikonfirmasi dan divalidasi panjang karakter/byte hasher, serta tidak boleh setara hash lama.
  Bcrypt dibatasi maksimal 72 byte tanpa mengganti algoritma atau hash akun yang ada. CSRF, MFA central, throttle,
  no-store dan transport HTTPS produksi tetap berlaku. Middleware forced-change memblokir aksi protected lain,
  tanpa menghalangi perubahan password/logout. Password/version global, revokasi recovery aktif dan audit berada
  dalam transaksi yang sama; audit gagal menyebabkan rollback. Sesi sendiri diakhiri dan sesi lama seluruh host
  user tersebut ditolak pada request berikutnya. Admin sekolah tidak diberi hak reset global milik orang lain.
- **F02:** Platform memanggil contract/action pemilik Tenancy dan Identity untuk provisioning/lifecycle/resend,
  tetap dalam transaksi/idempotency yang sama. Lock tenant digunakan secara konsisten sebelum mutasi workflow
  tenant-bound, termasuk activation/resend. Permission mapping dan staff implementation dipindah ke Platform;
  Identity menggunakan port netral untuk eligibility staff dan contract katalog permission untuk consumer Platform.
  `TenantContext`, hostname utility dan operation exception bersama berada di Support; session manager yang memakai
  DTO Identity berada di Identity. Provider/controller/middleware/views/routes/concurrency worker/test diselaraskan.
  Architecture tests memeriksa dependency dan write AST untuk pola M1 saat ini; bukan pemeriksa semantik PHP universal.
- **F03:** fault injection kegagalan invitation setelah bundle dibuat, kegagalan mail lalu resend tanpa reprovision,
  collision domain/reserved slug, creator XOR ganda, expiry undangan anggota, replay sesi setelah suspend/role/status,
  reset lintas A/B dan perubahan mandiri lintas tenant/central. Penolakan memeriksa state bisnis yang relevan;
  audit/idempotency kegagalan boleh tetap bertambah sesuai kontrak. Mail failure hanya mencatat kode stabil,
  tidak melaporkan exception transport yang dapat memuat credential/token.
- **Queue:** middleware `UseTenantContext` memverifikasi ulang locator server saat eksekusi dan membersihkan store
  dalam `finally`. Test memakai serialisasi/pipeline queue sync Laravel tanpa Queue fake, termasuk exception dan
  context palsu/revoked/suspended. Handler domain tetap wajib authorization actor/resource; belum ada job akademik
  atau bukti worker Redis lintas proses dari test ini.
- **Tooling/docs:** operator test menerima filter terbatas hanya pada operasi `test`; jalur migration/seed tidak
  berubah. README contract, README, CHANGELOG, index, gate, checklist/matrix diselaraskan. AGENTS lokal diperbaiki
  wording baseline ERD, tetapi tetap di-ignore. Tiga pengecualian ignore dokumen review berasal dari tahap sebelumnya.
- **Tidak berubah:** schema/migration, paket/lockfile, Docker M0, HBA/role/grant, password akun pilot dan data bisnis
  utama. Tidak ada seed/restore/rebuild utama, data siswa nyata, commit/stage/push/merge atau pekerjaan M2.
- **Proses lokal:** sesudah pemindahan namespace/binding, `queue:restart` mengirim sinyal graceful. Docker memulai
  ulang worker melalui restart policy yang sudah ada; konfigurasi tidak diubah dan tidak ada penghentian job paksa.
  Container worker kembali running dengan restart count 1; app/DB/web/Redis/Mailpit/scheduler tetap running.

### 8.3 Hasil verifikasi remediasi

Regresi penuh diulang pada kode final setelah koreksi guard byte; tidak ada test gagal pada run akhir.

| Perintah / pemeriksaan | Hasil aktual | Batas |
|---|---|---|
| `powershell -NoProfile -File scripts/m1-operator.ps1 test` | **100 test/791 assertion lulus**, 311,85 detik | Database khusus `lms_saas_test`; termasuk 11 password, 8 closure, 2 job, 3 architecture dan 9 DB contract test. Bukan 100 ditambah subset tersebut |
| `powershell -NoProfile -File tests/Support/m1-runtime-integration.ps1` | **2 test/51 assertion lulus**, 22,513 detik | Tmpfs PostgreSQL dan jaringan internal sintetis; termasuk perubahan password sebagai role runtime, bukan akun pilot |
| `docker compose exec -T app ./vendor/bin/pint --test` | **134 file lulus** | Tidak membuktikan behavior/security |
| `docker compose exec -T app composer validate --no-check-publish` | Valid | Bukan vulnerability audit |
| `node --test tests/Support/school_recovery_ui.test.js` | **3 test lulus** | Simulasi DOM/crypto, bukan browser HTTPS |
| `powershell -NoProfile -File scripts/m1-host-smoke.ps1` | **6 pemeriksaan lulus** | GET central/3 sekolah/unknown/loopback; tanpa mutasi akun pilot |
| `docker compose exec -T app php artisan route:list --json --except-vendor` | **49 route**, termasuk empat route perubahan password | Inventory, bukan demonstrasi browser |
| `php scripts/m1-check-runtime.php` melalui app/worker/scheduler | Ketiga service lulus | Koneksi baru dan hak audit, bukan eksekusi seluruh job |
| Owner wrapper, `BEGIN READ ONLY` | **3 tenant aktif / 4 user / 5 migration / 14 audit platform / 5 audit tenant** | Sama dengan baseline; bukan fingerprint seluruh isi data |
| Katalog/HBA read-only | **95 constraint tervalidasi, 7 rule SCRAM, 0 error HBA** | Tidak mengubah HBA, grant, atau role |
| `docker compose config --quiet`; resource runtime sementara | Lulus; tidak ada container/network latihan tertinggal | Konfigurasi tidak dicetak/interpolasi secret tidak dibagikan |

Uji runtime terisolasi dan JavaScript tetap dijalankan eksplisit; bukan bagian suite default/CI default.
CI sukses run `45137773386` hanya milik baseline `b07ebdf`. Belum ada hasil CI untuk working tree remediasi.

### 8.4 Error yang ditemukan dan ditangani

Pengulangan test menemukan assertion cache-control yang terlalu spesifik, metadata audit yang ditolak redactor,
fixture mail mock yang belum memulihkan mail manager, serta alasan audit yang belum memakai kode stabil.
Perbaikan mengikuti kontrak framework/redaction; redactor dan pengaman database test tidak dilonggarkan.
Pada penambahan guard byte, pemanggilan `Hash::getDriverName()` salah untuk Laravel terpasang; ini menghasilkan
9 test gagal pada run 100-test (91 lulus/729 assertion, 367,54 detik). Sudah dikoreksi ke `getDefaultDriver()`
yang tersedia pada HashManager lokal; run tersebut bukan hasil akhir dan tidak dihitung sebagai sukses.
Run 98-test sebelum guard byte sempat lulus 780 assertion, tetapi juga tidak menggantikan regresi kode final.
Probe `docker exec` worker yang dikirim terlalu dekat sinyal restart terputus dengan exit 137 ketika container
berhenti normal untuk restart. Ini probe diagnostik, bukan job/test gagal; verifikasi diulang dan lulus sesudah worker
running. Snapshot bisnis penutup juga tetap 3 tenant/4 user/5 migration/14 audit platform/5 audit tenant.

### 8.5 Gate yang tetap terbuka

1. Demonstrasi browser empat host/HTTPS (F04) belum dijalankan dalam remediasi ini. Kegagalan koneksi alat pada
   review awal tetap tercatat; tidak ada bukti screenshot/cookie/login baru. Konfigurasi TLS tidak diubah.
2. Pengguna perlu review/commit perubahan lalu memperoleh hasil CI pada commit tersebut; tidak ada push otomatis.
3. Approval akhir PR6/M1 baru diminta sesudah evidence applicable lengkap. Insiden PR5, keterbatasan backup/restore
   dan gate produksi pada §5 tetap berlaku; tidak menghapus riwayat atau menganggap data lama kembali.
4. Batas byte baru saat ini diterapkan pada alur perubahan mandiri saja. Pemakaian policy yang sama pada
   activation/invitation/recovery adalah pembaruan konsistensi terpisah yang memerlukan persetujuan, bukan klaim
   sudah seragam. Tidak ada migrasi algoritma password atau perubahan credential otomatis.

Saran commit setelah review: `feat: close M1 authentication and boundary review gaps`.

### 8.6 Inventory file terhadap baseline review

`M` = modifikasi, `D` = path lama dipindah, `??` = file baru belum dilacak. Inventory mencakup dokumentasi review
yang sudah dirty sebelum remediasi. Git dapat menampilkan lima pemindahan sebagai rename setelah staging;
tidak ada penghapusan data bisnis. Path relatif terhadap root `M0`:

```text
 M .gitignore
 M CHANGELOG.md
 M README.md
 M app/Http/Controllers/Auth/CentralAuthController.php
 M app/Http/Controllers/Auth/CentralMfaController.php
 M app/Http/Controllers/Auth/TenantAuthController.php
 M app/Http/Controllers/Platform/TenantLifecycleController.php
 M app/Http/Controllers/Platform/TenantProvisioningController.php
 M app/Http/Middleware/EnsureCentralMfaPending.php
 M app/Http/Middleware/EnsureCentralPermission.php
 M app/Http/Middleware/EnsureCentralSession.php
 M app/Http/Middleware/EnsureTenantSession.php
 M app/Http/Middleware/RequireCentralHost.php
 M app/Modules/Identity/Contracts/README.md
 M app/Modules/Identity/Services/BootstrapAdminActivationService.php
 M app/Modules/Identity/Services/CentralAuthenticator.php
 M app/Modules/Identity/Services/ExistingAccountInvitationService.php
 M app/Modules/Identity/Services/MemberInvitationService.php
 M app/Modules/Identity/Services/MembershipManagementService.php
 M app/Modules/Identity/Services/PermissionChecker.php
 D app/Modules/Identity/Services/PlatformPermissionChecker.php
 M app/Modules/Identity/Services/SchoolAssistedRecoveryService.php
 M app/Modules/Identity/Services/SelfEmailRecoveryService.php
 M app/Modules/Identity/Services/TenantAuthenticator.php
 M app/Modules/Platform/Contracts/README.md
 D app/Modules/Platform/Services/PlatformOperationException.php
 M app/Modules/Platform/Services/TenantInvitationDeliveryService.php
 M app/Modules/Platform/Services/TenantLifecycleService.php
 M app/Modules/Platform/Services/TenantMetadataService.php
 M app/Modules/Platform/Services/TenantProvisioningService.php
 M app/Modules/Tenancy/Contracts/README.md
 D app/Modules/Tenancy/Data/TenantContext.php
 M app/Modules/Tenancy/Services/TenantResolver.php
 D app/Modules/Tenancy/Support/Hostname.php
 M app/Providers/AppServiceProvider.php
 M app/Support/Audit/AuthAuditLogger.php
 D app/Support/Auth/AuthSessionManager.php
 M app/Support/Auth/LoginRateLimiter.php
 M app/Support/Tenancy/README.md
 M app/Support/Tenancy/TenantContextStore.php
 M docs/DOCUMENT_INDEX.md
 M docs/runbooks/M1_ARCHITECTURE_GATE.md
 M resources/views/auth/dashboard.blade.php
 M resources/views/platform/layout.blade.php
 M resources/views/platform/tenants/index.blade.php
 M resources/views/platform/tenants/show.blade.php
 M routes/central.php
 M routes/console.php
 M routes/tenant.php
 M scripts/m1-operator.ps1
 M scripts/m1-owner-exec.sh
 M tests/Feature/M1AuthRbacTest.php
 M tests/Feature/M1DatabaseContractTest.php
 M tests/Feature/M1HostContextTest.php
 M tests/Feature/M1IdentityFlowsTest.php
 M tests/Runtime/M1RuntimeRoleIntegrationTest.php
 M tests/Support/m1_identity_concurrency_worker.php
?? app/Http/Controllers/Auth/PasswordChangeController.php
?? app/Http/Middleware/RequirePasswordChange.php
?? app/Http/Requests/ChangePasswordRequest.php
?? app/Modules/Identity/Actions/ChangeOwnPassword.php
?? app/Modules/Identity/Actions/ManageBootstrapAdminAccounts.php
?? app/Modules/Identity/Actions/ManageTenantMemberships.php
?? app/Modules/Identity/Contracts/BootstrapAdminAccounts.php
?? app/Modules/Identity/Contracts/PermissionCatalogue.php
?? app/Modules/Identity/Contracts/TenantMemberships.php
?? app/Modules/Identity/Data/BootstrapInvitation.php
?? app/Modules/Identity/Queries/PermissionCatalogueQuery.php
?? app/Modules/Identity/Services/AuthSessionManager.php
?? app/Modules/Identity/Support/PasswordPolicy.php
?? app/Modules/Platform/Services/PlatformPermissionChecker.php
?? app/Modules/Platform/Services/PlatformStaffAccess.php
?? app/Modules/Tenancy/Actions/ManageTenantRegistry.php
?? app/Modules/Tenancy/Contracts/TenantRegistry.php
?? app/Support/Auth/CentralStaffAccess.php
?? app/Support/Exceptions/OperationException.php
?? app/Support/Http/Hostname.php
?? app/Support/Tenancy/Jobs/UseTenantContext.php
?? app/Support/Tenancy/TenantContext.php
?? app/Support/Tenancy/TenantContextResolver.php
?? docs/runbooks/M1_ACCEPTANCE_CHECKLIST.md
?? docs/runbooks/M1_FINAL_REVIEW.md
?? docs/runbooks/M1_TEST_MATRIX.md
?? resources/views/auth/change-password.blade.php
?? tests/Feature/M1PasswordChangeTest.php
?? tests/Feature/M1SecurityClosureTest.php
?? tests/Feature/M1TenantJobContextTest.php
?? tests/Support/M1Fixtures.php
?? tests/Support/TenantContextProbeJob.php
?? tests/Unit/M1ArchitectureTest.php
```
