# M2 PR1 migration plan and review

> **Pembaruan 28 November 2026:** PR1 disetujui pemilik melalui chat dan batch M2-A
> telah diterapkan pada database aplikasi lokal setelah preflight. Uraian draft dan
> checkbox di bawah adalah catatan historis persiapan, bukan status operasional terkini.
> Bukti pelaksanaan terkini dicatat di [architecture gate](M2_ARCHITECTURE_GATE.md).

| Metadata | Nilai |
|---|---|
| Versi | 0.1 |
| Tanggal | 28 November 2026 |
| Status | Persiapan PR1 selesai, Draft siap review; belum approval hasil PR1 atau izin DDL aplikasi |
| Baseline pengerjaan | `08195f8`, branch `Milestone-2`; working tree bersih saat mulai |
| Desain | PRD Academic dan amendment ERD v0.1 Accepted pada PR0 |
| Izin pekerjaan | Pengguna: "lanjutkan persiapan PR1"; PR2–PR6 belum dikerjakan |

PR1 menyiapkan kontrak/DTO, architecture guard, sinkronisasi ERD/diagram dan draf
schema M2-A. Database aplikasi `lms_saas` tetap tanpa tabel Academic. Izin desain
PR0 dan pekerjaan persiapan PR1 tidak menjadi izin apply DDL aplikasi.

## Acuan dan batas implementasi

- [PRD Academic](../prd/PRD_Modul_Academic_Administration_v0.1.md) ACAD-001/002/004/005,
  D01/D02/D06, serta persiapan batas ACAD-003/006/007 untuk tahap berikutnya.
- [Amendment ERD Accepted](../erd/ERD_M2_Academic_Amendment_v0.1.md) dan
  [diagram Academic](../erd/ERD_v1.1_02_Academic.mmd), dibaca bersama Core v1.1.
- [Master plan](M2_MASTER_PLAN.md) dan [architecture gate](M2_ARCHITECTURE_GATE.md).

Belum ada UI, CRUD domain Academic, models, service bindings atau adapter port baru.
PR2 menyediakan implementasi Identity/Tenancy dan workflow akademik beserta auth,
resource binding dan negative HTTP tests. Port undangan/reissue CSV tetap PR5;
course reader/checklist agregat central tetap PR4. Tidak ada permission baru, grant
administratif untuk guru, bypass platform, RLS, package atau perubahan Docker.

## Kontrak internal yang direview

| Pemilik | Contract dan DTO | Batas yang harus dijaga adapter PR2 |
|---|---|---|
| Identity | `MembershipEligibilityReader`, `MembershipSelection`, `MembershipEligibility` | ID server-resolved yang sudah diotorisasi; maksimal 100 per batch; filter tenant sebelum lookup; hanya ID/status/role/versi; fresh read, foreign ID tidak dikembalikan |
| Tenancy | `SchoolProfiles`, `SchoolProfile`, `SchoolProfileUpdate` | Context host aktif; read dengan school.view; lock/update dengan school.update, kedua auth version dan audit atomik; hanya name/timezone/accent |

Interface belum di-bind ke container karena implementasinya belum dibuat. Unit test
membuktikan kontrak tipe, validasi, batas batch dan immutability DTO, bukan membuktikan
query/permission/revocation adapter yang belum ada. Fakta role/status tidak mengganti
policy profil/pengampu/enrollment. Academic tidak boleh mengubah credential/membership
atau menulis metadata Tenancy secara langsung.

Architecture guard memuat owner seluruh 10 tabel, forbidden dependency edges dan
pembatasan Academic ke Contracts/Data Identity/Tenancy. Platform hanya boleh memakai
Contracts/Data Academic untuk read port yang disetujui, bukan membuka direktori siswa.
Guard merupakan pemeriksaan statis atas import literal dan query-builder writes yang
terlihat; belum mencakup seluruh grouped import, static Eloquent mutator atau SQL
dinamis. Cakupan itu perlu diperluas sebelum menambah adapter/model Academic, bersama
review/policy/runtime tests; bukan klaim seluruh akses database sudah dianalisis.

## File persiapan PR1

Path relatif repository yang baru/diubah pada tahap ini:

- `.gitignore`
- `README.md`
- `CHANGELOG.md`
- `app/Modules/Academic/Contracts/README.md`
- `app/Modules/Identity/Contracts/README.md`
- `app/Modules/Identity/Contracts/MembershipEligibilityReader.php`
- `app/Modules/Identity/Data/MembershipEligibility.php`
- `app/Modules/Identity/Data/MembershipSelection.php`
- `app/Modules/Tenancy/Contracts/README.md`
- `app/Modules/Tenancy/Contracts/SchoolProfiles.php`
- `app/Modules/Tenancy/Data/SchoolProfile.php`
- `app/Modules/Tenancy/Data/SchoolProfileUpdate.php`
- `database/migrations/pending_m2/2026_11_28_000100_create_m2_academic_foundation.php`
- `docs/DOCUMENT_INDEX.md`
- `docs/erd/ERD_M2_Academic_Amendment_v0.1.md`
- `docs/erd/ERD_v1.1_02_Academic.mmd`
- `docs/runbooks/M2_ARCHITECTURE_GATE.md`
- `docs/runbooks/M2_MASTER_PLAN.md`
- `docs/runbooks/M2_PR1_MIGRATION_PLAN.md`
- `tests/Feature/M2AcademicFoundationConcurrencyTest.php`
- `tests/Feature/M2AcademicFoundationTest.php`
- `tests/Support/M2FoundationFixtures.php`
- `tests/Support/m2_foundation_unique_worker.php`
- `tests/Unit/M1ArchitectureTest.php`
- `tests/Unit/M2FoundationContractsTest.php`

Dua file lokal juga disinkronkan tetapi tetap di-ignore: `AGENTS.md` dan
`docs/erd/ERD_Core_LMS_SaaS_v1.1.md`. Menyebutnya di manifest ini tidak mengubah
aturan Git atau menganggap file tersebut tersedia pada clone GitHub.

## Draf batch M2-A

File: `database/migrations/pending_m2/2026_11_28_000100_create_m2_academic_foundation.php`.
Satu migration, urutan create student_profiles → teacher_profiles → academic_years →
semesters → subjects. Down membalik urutan; hanya diuji pada DB test terisolasi.

SHA-256 isi file berakhiran LF saat review:
`e7cd868864aae8db0f147047a3e4b81b5c4e1244b54afffd6e127e9ad4c35aa7`.
Normalisasi CRLF ke LF sebelum membandingkan digest lintas checkout. Jika SQL/guard
atau versi file berubah, perbarui digest dan ulangi review/pengujian yang relevan.

Subfolder `pending_m2` tidak masuk discovery default Laravel; tes mengaksesnya lewat
`migrate --path=database/migrations/pending_m2` secara eksplisit. Up dan down juga
menolak environment selain testing, driver selain pgsql atau hasil current_database
selain `lms_saas_test`. Jadi setup/operator migrate rutin tidak menerapkan batch ini,
dan pemanggilan path langsung di aplikasi tetap ditolak sebelum perubahan schema.

| Tabel | Constraint penting yang diberi nama eksplisit |
|---|---|
| student_profiles | `m2_student_tenant_id_unique`, `m2_student_membership_unique`, `m2_student_membership_fk`, status active/inactive, NIS nullable/nonblank, `m2_student_nis_unique` non-null |
| teacher_profiles | `m2_teacher_tenant_id_unique`, `m2_teacher_membership_unique`, `m2_teacher_membership_fk`, status active/inactive, code nullable/nonblank, `m2_teacher_code_unique` non-null |
| academic_years | `m2_year_tenant_id_unique`, `m2_year_name_unique`, dates/status CHECK, `m2_year_one_active` partial per tenant |
| semesters | `m2_semester_tenant_id_unique`, `m2_semester_year_id_unique`, `m2_semester_code_unique`, `m2_semester_year_fk`, code/dates/status CHECK, `m2_semester_one_active` partial per tenant/year |
| subjects | `m2_subject_tenant_id_unique`, `m2_subject_code_unique`, nonblank code/name CHECK, is_active default true |

Seluruh tabel: PK BIGINT, ULID CHAR(26) unique/not-null, tenant_id not-null FK,
timestamps TIMESTAMPTZ, composite candidate key, index baca tenant/status atau active,
serta FK RESTRICT. Tiga FK tenant-owned (dua membership dan semester/year) membawa
tenant_id; lima FK tenant langsung tidak menggantikannya. Nama final draf diperiksa
terhadap katalog PostgreSQL dan batas identifier 63 byte; tidak menggunakan nama
FK komposit otomatis yang dapat terpotong.

M2-B belum dibuat: classrooms/placement pada PR3; courses/teachers/roster pada PR4.
Tidak ada backfill, rebuild tenant/user, edit migration M1 Ran, cascade history,
seed domain atau tabel impor/outbox baru. CHECK/FK tidak membuktikan semester berada
dalam tahun, tanpa overlap, parent active, role/profile eligible atau closed immutable;
itu action/policy dan pengujian PR2–PR4, bukan constraint tambahan diam-diam.

## Pengujian dan bukti

Perintah khusus memakai operator existing dengan owner secret hanya di container
sekali pakai; app/worker tetap runtime. Launcher memaksa `lms_saas_test` dan storage/
bootstrap cache privat di `/tmp`, sehingga hasil CLI tidak mengubah cache/log web.

```powershell
.\scripts\m1-operator.ps1 test -TestFilter '(M2|M1Architecture)'
```

Run khusus lulus 43 test/477 assertion, termasuk up/down/up via Artisan, validasi
katalog FK/index/check, penolakan cross-tenant profile/year, duplicate/null identifier,
state/date, FK RESTRICT, discovery default, guard environment, kontrak/DTO dan owner
boundaries. Dua race PostgreSQL memakai proses independen yang terbukti menunggu
unique index; masing-masing tepat satu insert sukses dan satu ditolak index yang benar.

Run awal berhenti karena test immutability mencoba menulis temporary expression PHP;
test diperbaiki menggunakan variabel, lalu run di atas lulus. Tidak ada DDL aplikasi
dalam run gagal maupun run ulang. Pint file PHP baru/diubah (13 file) lulus dan
`composer validate --no-check-publish` menyatakan composer.json valid.

Pemeriksaan seluruh project dengan `docker compose exec -T app ./vendor/bin/pint --test`
lulus untuk 147 file. Smoke HTTP tamu lewat `m1-host-smoke.ps1` lulus 6 pemeriksaan:
central, tiga sekolah sintetis, unknown host dan loopback. Tidak login/mengirim formulir.

Regresi penuh pertama: 142 lulus, 2 gagal, 1241 assertion, 563,64 detik. Kedua kegagalan
adalah `ProcessTimedOutException` dari subprocess pada `M1TestRuntimeIsolationTest`
dengan batas 15 detik; bukan kegagalan assertion FK/tenant. Pengulangan test runtime
sendirian kemudian lulus 4 test/25 assertion dalam 54,81 detik, tanpa perubahan source,
timeout atau launcher. Run gagal tetap dicatat; hasil filtered tidak disebut sebagai
regresi penuh hijau. Regresi penuh kemudian diulang tanpa pemeriksaan Docker lain
bersamaan dan lulus 144 test/1247 assertion dalam 511,99 detik. Tidak ada source,
konfigurasi timeout atau launcher yang diubah di antara kedua run penuh tersebut.

Perintah regresi penuh: `.\scripts\m1-operator.ps1 test`. Pemeriksaan tambahan
`docker compose exec -T --user www-data app php scripts/m1-check-runtime.php` lulus:
koneksi tetap runtime bukan owner, dan audit tetap SELECT/INSERT saja.

Snapshot read-only aplikasi sebelum/sesudah seluruh pengujian cocok persis. Fingerprint
schema SHA-256: `39b3617fe297aa42b801ce5a78a827737745b831e94d86bbba8d8adcb40d07b7`.
Jumlah baris yang diukur tetap: migrations 5, tenants 3, users 4, memberships 3,
invitations 3, recovery requests 1, tenant audit 17 dan platform audit 20. Ini pembanding
schema/jumlah baris, bukan checksum setiap nilai data. Tidak membaca PII atau secret
ke output. Probe permission sebagai www-data: 0 Blade root-owned/tidak terbaca, log
writable, dan 0 direktori test runtime di container app; tidak melakukan chown/cleanup.

Pemeriksaan statis lulus untuk 42 tautan file lokal (anchor belum diperiksa), 10
entitas diagram, digest draf dan pemisahan approval PR0 vs PR1/DDL/PR2. Manifest 25
path kandidat Git cocok dengan worktree; enam baseline lama dan guard privat tetap
di-ignore. `git diff --check` lulus. Mermaid belum dirender secara visual.

Ini belum acceptance CRUD/browser M2, uji grants runtime pada tabel aplikasi baru,
backup pre-DDL baru, login/MFA/recovery HTTPS, uji beban, atau CI GitHub baru.
Ringkasan evidence dan status review juga dicatat pada gate PR1.

## Preflight sebelum apply aplikasi yang terpisah

- [ ] Pemilik mereview hasil PR1, signature contract dan SQL/digest batch M2-A.
- [ ] Pemilik memberi izin DDL spesifik untuk `lms_saas`, bukan hanya menerima PR1.
- [ ] Operator merekam target/container yang benar, migration Ran/pending, owner/runtime
  bukan saling tertukar, serta schema saat itu tanpa tabel/objek konflik.
- [ ] Backup baru terenkripsi/privat tersedia, restore dapat dibuktikan dan target
  pemulihan terpisah; jangan memakai snapshot seluruh DB untuk diberikan ke sekolah.
- [ ] Review output Composer/migrate/seed yang masih dapat membuat file root sebelum
  apply; jangan melemahkan umask 077, chmod 777 atau chown storage privat rekursif.
- [ ] Setelah izin, finalisasi draf menjadi migration normal yang direview, dengan
  jalur/guard aplikasi yang sesuai dan digest baru; ulangi tes sebelum apply. Tidak
  sekadar melewati guard menggunakan APP_ENV testing pada database aplikasi.
- [ ] Apply additive hanya batch yang direview, tanpa migrate:fresh/rollback M1;
  terapkan ulang grants lewat operator M1, lalu buktikan DML Academic dan audit tetap
  SELECT/INSERT saja sebagai lms_runtime. Jangan jalankan grants produksi di DB lain.
- [ ] Verifikasi schema, data M1, log/cache web dan smoke host; bila gagal hentikan
  rollout. Data baru yang sudah dipakai memerlukan forward-fix/restore yang direview,
  bukan down yang menghapus histori.

Seluruh checkbox preflight tetap terbuka; persiapan PR1 tidak mengklaim semua syarat
tersebut telah dipenuhi. Menjalankan migrate/status/grants pada aplikasi bukan bagian
otomatis dari perintah test di atas. Approval hasil PR1 dan izin PR2 juga terpisah.

## Dampak dan risiko tersisa

Database aplikasi tetap M1. Tabel test baru tidak menerima grants aplikasi secara
otomatis. Tidak ada route/permission/credential/session policy yang diubah. Core dan
diagram disinkronkan dengan amendment Accepted tanpa menambah keputusan D01–D06.
Core baseline lokal tetap di-ignore; diagram dan plan dipilih sempit untuk Git.

Risiko lanjutan: adapter port belum tersedia, business invariants belum ditegakkan,
dan workflow operator aplikasi belum ditinjau untuk output root. Masing-masing harus
ditutup pada tahap/gate terkait, bukan dengan menganggap DTO atau schema test sudah
menjadi fitur akademik yang aman dipakai sekolah.

Saran commit: `feat: prepare M2 PR1 contracts and isolated academic schema tests`.
