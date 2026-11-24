# M1 TEST MATRIX — Wajib PostgreSQL + Laravel Feature Tests

`php artisan test` harus berjalan dengan DB `lms_saas_test` (konfigurasi `phpunit.xml` M0). Jangan memakai akun sekolah nyata. Semua test negatif harus memverifikasi **tidak terjadi perubahan DB** setelah request ditolak, bukan hanya HTTP status.

| ID | Scenario | Expected |
|---|---|---|
| M1-DB01 | `role_permissions` menunjuk platform permission | Ditolak composite FK/check |
| M1-DB02 | `membership_roles` memakai tenant B & membership A | Ditolak FK komposit |
| M1-DB03 | Dua primary active domain pada tenant sama | Ditolak partial unique |
| M1-DB04 | Username lokal sama di A dan B | Diizinkan untuk user berbeda; tidak tertukar |
| M1-DB05 | `users.status=active` tanpa password hash | Ditolak CHECK |
| M1-DB06 | Invitation creator kosong atau ganda | Ditolak XOR CHECK |
| M1-DB07 | Provision same actor+idempotency hash berulang | Ditolak index unik |
| M1-HOST01 | Host tidak terdaftar mengakses `/login` | 404/421, bukan fallback sekolah |
| M1-HOST02 | Header `X-Tenant-ID` palsu pada hostname A | Tidak mengubah tenant context |
| M1-HOST03 | Domain revoked/unverified | Ditolak resolver |
| M1-AUTH01 | Akun global membership A & B, role berlainan | Masing-masing host sesuai role sendiri |
| M1-AUTH02 | Login sekolah A lalu request sekolah B tanpa login B | Tidak terautentikasi di B |
| M1-AUTH03 | Tenant suspended, sesi sebelumnya valid | Request baru ditolak |
| M1-AUTH04 | User suspended/membership left | Request berikutnya ditolak |
| M1-AUTH05 | Role diubah, session auth_version lama | Privileged request ditolak |
| M1-AUTH06 | Reset password global, sesi lama A dan B | Kedua sesi ditolak |
| M1-AUTH07 | School admin mencoba menambah platform permission | 403 dan tidak ada grant |
| M1-PROV01 | Submit sama, actor/key/payload sama dua kali | Tenant tepat satu; hasil sama |
| M1-PROV02 | Key sama, digest payload berbeda | HTTP 409; tenant tidak berubah |
| M1-PROV03 | Slug/hostname bentrok atau reserved `admin` | 409/422; no half-tenant; tidak menguasai central hostname |
| M1-PROV04 | Provision transaction gagal membuat invitation | Rollback tenant/domain/settings |
| M1-PROV05 | Email undangan gagal terkirim | Tenant tetap pending, resend token baru aman |
| M1-ACT01 | Activation token A dikirim pada host B | Gagal, status tidak berubah |
| M1-ACT02 | Activation token expired | Gagal |
| M1-ACT03 | Activation token dipakai dua kali / konkuren | Tepat satu sukses |
| M1-ACT04 | Pending tenant punya admin belum aktif | Tidak bisa transition active |
| M1-ACT05 | Readiness lengkap + staff autorizasi | Pending → active sekali + audit |
| M1-REC01 | School-assisted untuk user 2 membership (1 left) | Ditolak; hitung semua historis |
| M1-REC02 | School-assisted untuk platform staff | Ditolak |
| M1-REC03 | Recovery token reuse | Ditolak |
| M1-SEC01 | Admin A membuka membership B via ULID/id | 403/404, no data |
| M1-SEC02 | Super Admin mencoba melihat nilai siswa | Tidak ada route/bypass academics |
| M1-SEC03 | Audit `metadata_json` memuat password/token | Gagal validasi dan test redaction |
| M1-SEC04 | Job A diikuti job B | TenantContext tidak terbawa antar-job |
| M1-UI01 | Dashboard pusat menunjukkan 3 tenant sintetis | Angka riil DB, tanpa PII |
| M1-UI02 | School admin membuka halaman central | Tidak dapat mengakses tenant management |

## Evidence review — 24 November 2026

Snapshot berikut adalah review awal commit `b07ebdf`; pemetaan di bawah sudah diperbarui untuk remediasi F01–F03
yang diizinkan pengguna. Hasil regresi remediasi dan batasnya dicatat pada §8 [M1_FINAL_REVIEW.md](M1_FINAL_REVIEW.md).
CI snapshot lama tidak membuktikan perubahan working tree yang belum di-commit/push.

**Remediasi final:** full suite **100 test/791 assertion lulus**, 311,85 detik (termasuk 9 DB contract, 11 password,
8 security closure, 2 queue sync dan 3 architecture tests); runtime terisolasi **2 test/51 assertion lulus**.
Pint **134 file**, JavaScript **3 test**, HTTP host smoke **6 pemeriksaan** dan Composer valid; semua lulus lokal.
Tidak ada bukti baru browser/HTTPS atau CI commit remediasi.

```text
Branch/Commit: Milestone-1 / b07ebdf182080f184065caf5b60a2b81c9efafaa
Laravel/PHP/PostgreSQL: 13.40.0 / 8.4.27 / 17.11
Local migration status (read-only): 5 Ran; tidak menjalankan migration database utama
Full existing suite: PASS — 75 test, 513 assertion, 324.09 detik
DB contract subset: PASS — 8 test dalam full suite
Concurrency/idempotency: PASS — 6 identity/lifecycle + 1 provisioning test dalam full suite
Runtime isolated PostgreSQL: PASS — 2 test, 43 assertion
Composer/Pint/JavaScript: valid / PASS 110 file / PASS 3 test
HTTP host smoke: PASS — central, 3 tenant, unknown host, loopback (6 checks)
Browser/HTTPS: NOT RUN — koneksi browser terblokir missing field sandboxPolicy
PR6/M1: changes requested; bukan complete dan bukan approval produksi
```

CI untuk commit di atas: [M0 checks run 45137773386](https://github.com/Alfdhiw/LMS_SaaS/actions/runs/45137773386),
job `smoke` dan `private-database-helper` sukses, termasuk lab SCRAM. Metadata dibaca pada 24 November 2026;
tidak ada push atau eksekusi workflow baru oleh reviewer. Test JavaScript dan runtime terisolasi dijalankan lokal,
tidak otomatis merupakan langkah workflow default.

### Pemetaan suite ke matrix

Semua nama kelas di bawah berada di `tests/Feature/`, kecuali runtime di `tests/Runtime/`.
**Teruji** berarti tersedia assertion langsung untuk skenario yang disebut; tidak berarti seluruh permutasi,
failure mode, atau seluruh efek samping sudah dibuktikan. Remediasi menambahkan feature/architecture tests,
middleware job, dan alur perubahan password; test runtime tetap terpisah dari suite default.

| ID | Bukti saat review | Status / batas |
|---|---|---|
| M1-DB01–05, DB07 | `M1DatabaseContractTest`: scope permission, FK membership, primary domain, username, password null, idempotency | Teruji |
| M1-DB06 | `M1DatabaseContractTest` + `M1SecurityClosureTest::test_invitation_with_both_creators_is_rejected_and_preserves_existing_rows` | Teruji actor kosong/ganda, SQLSTATE 23514 dan state sesudah penolakan |
| M1-HOST01–03 | `M1HostContextTest`: unknown/revoked/unverified/pending, header palsu | Teruji untuk fixture yang tersedia |
| M1-AUTH01–02 | `M1AuthRbacTest`: username/role antar-host; runtime integration memakai role runtime | Teruji; bukan perilaku cookie browser riil |
| M1-AUTH03 | `M1ProvisioningTest` + `M1SecurityClosureTest::test_suspension_rejects_an_existing_protected_session_without_mutating_other_tenant` | Teruji replay sesi lama setelah suspend melalui HTTP, write ditolak dan tenant B tetap dapat diakses |
| M1-AUTH04 | `M1SecurityClosureTest::test_global_suspension_and_left_membership_reject_stale_sessions_without_writes` | Teruji dua status terhadap request protected lama dan snapshot state bisnis |
| M1-AUTH05 | `M1AuthRbacTest` + `M1SecurityClosureTest::test_role_change_rejects_old_session_in_a_without_revoking_membership_b` | Teruji mutasi role melalui HTTP, replay sesi A ditolak dan sesi B tetap valid |
| M1-AUTH06 | `M1IdentityFlowsTest` + `M1PasswordChangeTest` | Teruji replay sesi lama A dan B setelah recovery global; perubahan mandiri juga mencabut sesi central milik user yang sama |
| M1-AUTH07 | DB contract, seeder dan mutasi role terlarang pada `M1AuthRbacTest` | Teruji HTTP 403 untuk role platform terlarang dan tidak ada grant/state target berubah; role tak dikenal biasa tetap 422 |
| M1-PROV01–02 | `M1ProvisioningTest` + `M1ProvisioningConcurrencyTest` | Teruji sequential dan concurrent untuk key sama |
| M1-PROV03 | `M1ProvisioningTest` + `M1SecurityClosureTest::test_domain_collision_and_reserved_central_slug_cannot_leave_a_partial_tenant` | Teruji slug/domain collision serta reserved central slug; bundle bisnis tidak berubah. Record idempotency/audit kegagalan memang boleh bertambah |
| M1-PROV04 | `M1SecurityClosureTest::test_invitation_insert_failure_rolls_back_all_previously_created_bundle_rows` | Teruji fault injection setelah tenant/domain/settings/user/membership dibuat; seluruh bundle di-rollback, failure request/audit tetap tercatat |
| M1-PROV05 | `M1SecurityClosureTest::test_mail_transport_failure_is_audited_and_resend_recovers_without_reprovisioning` | Teruji transport exception -> tenant pending -> audit kode aman -> resend dengan token baru; tidak reprovision atau log exception transport |
| M1-ACT01–02 | `M1ProvisioningTest` + expiry undangan anggota biasa di `M1SecurityClosureTest` | Teruji wrong host/replay/expiry bootstrap dan expiry undangan anggota tanpa perubahan state |
| M1-ACT03 | `M1IdentityConcurrencyTest::test_parallel_bootstrap_activation_consumes_invitation_once` | Teruji dua proses |
| M1-ACT04–05 | `M1ProvisioningTest`: readiness, activation explicit dan audit | Teruji untuk assertion yang tersedia |
| M1-REC01–03 | `M1AuthRbacTest`, `M1IdentityFlowsTest`, `M1IdentityConcurrencyTest` | Teruji historical membership/staff, dua sesi, replay/expiry/cache loss dan konsumsi concurrent |
| M1-SEC01 | `test_membership_mutations_are_tenant_scoped_and_revoke_sessions` | Teruji penolakan target B dan membership B tidak berubah |
| M1-SEC02 | Inventory 49 route, tidak ada route/modul akademik; tidak ditemukan universal Gate/Policy before | Tidak tersedia akademik di M1; bukan bukti policy grade/file M2–M4 |
| M1-SEC03 | `M1PlatformDashboardTest`: nested sensitive keys, rollback audit, field viewer terpilih | Teruji subset; bukan deteksi secret di setiap nilai bebas |
| M1-SEC04 | `M1HostContextTest` + `M1TenantJobContextTest` | Teruji serialisasi/eksekusi job melalui queue sync Laravel tanpa Queue fake: A -> exception -> B; context dibersihkan; host palsu/revoked/suspended ditolak sebelum handler. Bukan uji worker Redis lintas proses |
| M1-UI01–02 | `M1PlatformDashboardTest`, `M1AuthRbacTest`: agregat 3 tenant, portal/permission terpisah | Teruji feature test; demonstrasi browser empat host belum selesai |

### Requirement tambahan dan batas evidence

- AUTH-06: `M1PasswordChangeTest` menguji sandi lama, larangan target injection, kekuatan/konfirmasi/batas byte,
  ekuivalensi hash bcrypt lama, CSRF, MFA central, host binding, rate limit, audit atomik, rollback dan revokasi global.
  `must_change_password` memblokir aksi protected lain sampai ganti password; tidak menambah hak reset admin sekolah.
- `M1ArchitectureTest` memeriksa arah dependency dan write Query Builder/model/raw mutation lintas owner dengan AST,
  termasuk fixture alias negatif. Ini guard untuk pola kode M1 saat ini, bukan analisis semantik PHP menyeluruh.
- `M1DatabaseContractTest` memeriksa nama constraint dan partial index yang dideklarasikan migration, validasi katalog,
  serta negatif kritis. Bukan fuzzing exhaustive seluruh kombinasi input ERD.
- Assertion negatif memeriksa state bisnis yang relevan, bukan hanya status. Audit/idempotency penolakan boleh bertambah
  sesuai kontrak; tidak mengharuskan seluruh database identik sesudah aksi yang memang wajib diaudit.
- Checklist dan laporan mempertahankan insiden database PR5; suite hijau sekarang bukan bukti data lama tidak pernah hilang.
- Browser empat host/HTTPS dan CI commit remediasi masih memerlukan evidence terpisah; tidak dicentang oleh test lokal.

Rujuk [M1_FINAL_REVIEW.md](M1_FINAL_REVIEW.md) untuk temuan, prioritas, dan syarat penutupan. Semua poin applicable
harus memiliki bukti sebelum PR6/M1 di-approve; jangan menghapus atau menurunkan expected matrix untuk membuatnya lulus.
