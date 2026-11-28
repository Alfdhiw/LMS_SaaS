# Amendment ERD Academic M2

| Metadata | Nilai |
|---|---|
| Versi | 0.1 |
| Tanggal | 27 November 2026 |
| Status | Accepted — approval PR0 pengguna 28 November 2026; bukan izin DDL |
| Baseline yang dilengkapi | [ERD Core v1.1 Accepted](ERD_Core_LMS_SaaS_v1.1.md) §4/§4.1/§8/§12 |
| Kebutuhan | [PRD Academic v0.1](../prd/PRD_Modul_Academic_Administration_v0.1.md), ACAD-001–007 |

Sepuluh tabel Academic sudah ada pada desain ERD v1.1, tetapi belum ada migration M2
di baseline kode `4b29461`. Amendment ini melengkapi rincian physical schema dan aturan
M2, disetujui melalui [gate PR0](../runbooks/M2_ARCHITECTURE_GATE.md) pada 28 November
2026. Core v1.1 dan amendment ini digunakan bersama: rincian M2 yang diperjelas di
sini mengikuti amendment, sedangkan M1/domain lain tetap mengikuti Core. Sinkronisasi
anotasi dictionary/diagram aktif menjadi pekerjaan PR1 sebelum migration final.
Tidak ada SQL eksekusi atau perubahan tabel M1 pada tahap dokumen ini.

## Konvensi schema yang disetujui

Semua tabel dimiliki Academic, klasifikasi `tenant_owned`, dengan `id BIGINT` PK,
`tenant_id BIGINT NOT NULL` FK tenants, `UNIQUE(tenant_id,id)`, dan timestamps mutable
`created_at`/`updated_at` TIMESTAMPTZ. Konvensi seragam `public_ulid CHAR(26) NOT NULL UNIQUE`
pada seluruh 10 tabel, termasuk pivot domain ber-ID, agar mutasi/URL/audit tidak
mengandalkan ID internal. ULID tidak menggantikan policy tenant.

Default referential action `ON DELETE RESTRICT`; jangan cascade sejarah akademik.
Indeks child mengikuti prefix tenant dan FK/query; jangan duplikasi indeks unique.
Normalisasi kode oleh action/FormRequest konsisten dengan check DB yang disepakati.
Empty NIS/employee code menjadi null. DATE untuk kalender, TIMESTAMPTZ untuk event UTC.

| Tabel | Kolom domain, ukuran dan default | Unique tambahan dan CHECK |
|---|---|---|
| `student_profiles` | `membership_id BIGINT NOT NULL`, `nis VARCHAR(64) NULL`, `status VARCHAR(20)` default inactive | `(tenant_id,membership_id)`; partial `(tenant_id,nis)` non-null; status active/inactive, nis non-blank bila terisi |
| `teacher_profiles` | `membership_id BIGINT NOT NULL`, `employee_code VARCHAR(64) NULL`, `status VARCHAR(20)` default inactive | `(tenant_id,membership_id)`; partial `(tenant_id,employee_code)` non-null; status active/inactive, code non-blank bila terisi |
| `academic_years` | `name VARCHAR(40)`, `starts_on DATE`, `ends_on DATE`, `status VARCHAR(20)` default draft; semua NOT NULL | `(tenant_id,name)`; partial `(tenant_id)` WHERE active; status draft/active/closed; starts_on < ends_on |
| `semesters` | `academic_year_id BIGINT`, `code VARCHAR(4)`, `starts_on DATE`, `ends_on DATE`, `status VARCHAR(20)` default draft; semua NOT NULL | `(tenant_id,academic_year_id,code)`; `(tenant_id,academic_year_id,id)`; code odd/even; draft/active/closed; starts_on < ends_on; partial satu semester active per `(tenant_id,academic_year_id)` |
| `subjects` | `code VARCHAR(32)`, `name VARCHAR(150)` NOT NULL; `is_active BOOLEAN NOT NULL` default true | `(tenant_id,code)`; code/name non-blank |
| `classrooms` | `academic_year_id BIGINT`, `code VARCHAR(32)`, `label VARCHAR(100)`, `grade_level SMALLINT` NOT NULL; `homeroom_teacher_profile_id BIGINT NULL` | `(tenant_id,academic_year_id,code)`; `(tenant_id,academic_year_id,id)`; grade_level 7..9; code/label non-blank |
| `classroom_enrollments` | `academic_year_id`, `classroom_id`, `student_profile_id` BIGINT NOT NULL; `joined_on DATE NOT NULL`, `left_on DATE NULL`, `status VARCHAR(20)` default active | `(tenant_id,student_profile_id,id)`; partial `(tenant_id,academic_year_id,student_profile_id)` WHERE active; active/left; active membutuhkan left_on NULL, left membutuhkan left_on IS NOT NULL dan left_on >= joined_on |
| `courses` | `academic_year_id`, `semester_id`, `classroom_id`, `subject_id` BIGINT NOT NULL; `title VARCHAR(200) NOT NULL`; `status VARCHAR(20)` default draft | `(tenant_id,semester_id,classroom_id,subject_id)`; draft/active/closed; title non-blank |
| `course_teachers` | `course_id`, `teacher_profile_id` BIGINT NOT NULL; `assignment_role VARCHAR(20)` default teacher, `status VARCHAR(20)` default active, `assigned_at TIMESTAMPTZ` NOT NULL | `(tenant_id,course_id,teacher_profile_id)`; assignment_role teacher; status active/inactive |
| `course_enrollments` | `course_id`, `student_profile_id` BIGINT NOT NULL; `status VARCHAR(20)` default active; `enrolled_at TIMESTAMPTZ NOT NULL`, `withdrawn_at TIMESTAMPTZ NULL`; `source_classroom_enrollment_id BIGINT NULL` | `(tenant_id,course_id,student_profile_id)`; active/withdrawn; active membutuhkan withdrawn_at NULL, withdrawn membutuhkan withdrawn_at IS NOT NULL dan withdrawn_at >= enrolled_at |

Kolom domain yang tidak ditandai NULL pada tabel di atas adalah NOT NULL.
Default timestamp event ditentukan server/action, bukan waktu bebas dari client.
Nama constraint/index final dan panjang nama PostgreSQL harus direview pada PR1/PR3/PR4
sebelum DDL, bukan dihasilkan otomatis dari tabel panjang tanpa pemeriksaan.

## Foreign key dan candidate key

Seluruh FK tenant-owned ke parent mencakup tenant yang sama. FK ke `tenants` langsung
tidak menggantikan FK komposit ke membership/profil atau parent akademik.

| Child dan kolom | Parent yang wajib cocok |
|---|---|
| student/teacher profile `(tenant_id,membership_id)` | `tenant_memberships(tenant_id,id)` |
| semesters/classrooms `(tenant_id,academic_year_id)` | `academic_years(tenant_id,id)` |
| classrooms `(tenant_id,homeroom_teacher_profile_id)` | `teacher_profiles(tenant_id,id)` bila terisi |
| classroom_enrollments `(tenant_id,academic_year_id,classroom_id)` | `classrooms(tenant_id,academic_year_id,id)` |
| classroom_enrollments `(tenant_id,student_profile_id)` | `student_profiles(tenant_id,id)` |
| courses `(tenant_id,academic_year_id,semester_id)` | `semesters(tenant_id,academic_year_id,id)` |
| courses `(tenant_id,academic_year_id,classroom_id)` | `classrooms(tenant_id,academic_year_id,id)` |
| courses `(tenant_id,subject_id)` | `subjects(tenant_id,id)` |
| course_teachers `(tenant_id,course_id)` dan `(tenant_id,teacher_profile_id)` | `courses(tenant_id,id)` dan `teacher_profiles(tenant_id,id)` |
| course_enrollments `(tenant_id,course_id)` dan `(tenant_id,student_profile_id)` | `courses(tenant_id,id)` dan `student_profiles(tenant_id,id)` |
| course_enrollments `(tenant_id,student_profile_id,source_classroom_enrollment_id)` | `classroom_enrollments(tenant_id,student_profile_id,id)` bila source terisi |

Tidak menambah FK hanya pada source `id`. App enrollment baru mengisi source yang
terverifikasi, tetapi kolom tetap nullable sesuai ERD; ini bukan klaim DB mewajibkan
setiap row mempunyai source. Kesamaan classroom/year antara source dan course adalah
validasi domain di dalam transaksi, bukan dijamin oleh FK source tiga kolom tersebut.

## Perbedaan yang disetujui pada PR0

| Keputusan PRD | Delta terhadap ERD v1.1 |
|---|---|
| D01 | Partial unique semester active per tahun, CHECK tanggal semester, aturan active parent/overlap/close melalui action dan lock |
| D02 | Default profil inactive, panjang kolom NIS/code dan null/blank; eligibility user/membership/role di domain, bukan FK role |
| D03 | Merinci course draft/active/closed dan assignment_role teacher yang sebelumnya belum enumerated lengkap |
| D04 | Merinci classroom enrollment active/left, check tanggal/status, penggunaan source komposit dan audit re-enroll |
| D05 | Tidak ada tabel impor baru; marker audit atomik + lock tenant melindungi retry; perlu port undangan/reissue Identity |
| D06 | Tidak menambah kolom profil sekolah; memakai name/timezone/accent existing melalui Tenancy |
| Konvensi physical | ULID seluruh 10 tabel, ukuran/nullability/default/timestamps dan nama constraint direview sebagai bagian schema plan |

Diagram hubungan Academic baseline masih menjadi peta relasi; amendment ini tidak
menambah entitas atau edge di luar 10 tabel dan FK yang sudah dirancang. Diagram/
dictionary aktif harus disinkronkan pada PR1 sebelum migration final, terutama
anotasi status, source dan candidate keys. Amendment ini melengkapi, bukan
supersede keseluruhan Core v1.1; persetujuan desain tidak membuktikan schema terpasang.

## Invariant domain yang tidak dijamin FK

- Semester berada dalam tahun, tidak overlap pasangan, dan active hanya bila tahun
  active. Parent date/status edits wajib lock dan validasi ulang seluruh anak terkait;
  tahun hanya closed setelah seluruh semester yang ada closed.
- Eligibility profil, role teacher/homeroom/student, status user/membership/tenant,
  pengampu efektif terakhir, serta kesiapan aktivasi course adalah policy/action.
- Source placement harus sesuai classroom/year course ketika enroll/re-enroll.
  Placement active/left dan course roster adalah histori berbeda.
- Transfer/withdraw/reactivation dan marker impor harus atomik dengan audit; larangan
  closed mutation/hard-delete bukan hanya validasi form. Gunakan lock berurutan yang
  sama dengan context Tenancy; jangan mengandalkan unique constraint untuk semua aturan.

## Batch additive dan perlindungan data existing

1. M2-A / PR1: profiles guru/siswa, academic_years, semesters dan subjects; parent
   candidate keys selesai sebelum M2-B. Tidak mengubah tabel credentials/M1.
2. M2-B bagian pertama / PR3: classrooms dan classroom_enrollments.
3. M2-B bagian kedua / PR4: courses, course_teachers dan course_enrollments.

Data existing M1 tidak perlu di-rebuild/backfill ke tabel baru. Tidak mengedit migration
M1 yang sudah Ran, tidak menjalankan migrate:fresh/rollback pada aplikasi, dan tidak
menghapus tenant/user. Rollback schema baru hanya di DB test terisolasi; aplikasi yang
sudah berisi data memakai rencana pemulihan/forward-fix yang direview.

Sebelum apply lokal: verifikasi backup/target, daftar migration pending, owner operator,
permission runtime dan persetujuan DDL batch tersebut. Sesudah apply, terapkan ulang
grants melalui operator M1, lalu uji DML Academic sebagai runtime dan penolakan
UPDATE/DELETE/TRUNCATE audit. Jangan memberi owner/superuser kepada app/worker.
Review output Composer/migration/seed yang masih dapat membuat file root dilakukan
sebelum apply, tanpa melemahkan `umask 077` atau chown storage privat secara rekursif.

## Bukti yang wajib tersedia sebelum merge schema

PostgreSQL test dari kosong dan up/down batch baru; katalog FK/index/check tervalidasi;
penolakan cross-tenant profile/subject/teacher/source; penolakan course beda tahun;
dua active year/semester dan dua placement active ditolak secara konkuren; invalid
tanggal/status ditolak di lapisan yang tepat; domain eligibility/revocation diuji;
audit failure rollback; role runtime bukan owner dan tetap tidak dapat mengubah audit.
SQLite tidak menggantikan bukti ini. Lihat [master plan](../runbooks/M2_MASTER_PLAN.md).

## Riwayat versi

| Versi | Tanggal | Perubahan |
|---|---|---|
| 0.1 | 2026-11-27 | Proposal physical schema 10 tabel Academic dan batch additive |
| 0.1 — approval | 2026-11-28 | Pengguna menyetujui semua PR0; amendment Accepted tanpa perubahan desain; dictionary/diagram dan DDL tetap mengikuti gate PR1 |
