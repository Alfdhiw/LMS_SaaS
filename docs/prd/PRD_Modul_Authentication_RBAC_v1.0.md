# PRD Modul Authentication & RBAC — LMS SaaS Multi-Sekolah

| Metadata | Nilai |
|---|---|
| ID dokumen | PRD-AUTH-RBAC-001 |
| Versi | 1.0 |
| Tanggal | 5 November 2026 |
| Status | **Proposed baseline — review dan persetujuan sebelum implementasi** |
| Prioritas | P0, Milestone M1 (fondasi M0 harus tersedia) |
| Dokumen induk | `PRD_Master_LMS_SaaS_Multi_Sekolah_v1.0.md` |
| Arsitektur induk | `ADR_001_Multi_Tenancy_Identity_Architecture_v1.0.md` |
| Skema induk | `ERD_Core_LMS_SaaS_v1.0.md` |
| Dokumen pasangan | `PRD_Modul_SaaS_Central_Tenant_Management_v1.0.md` |

> **Sumber keputusan:** target bisnis multi-sekolah dan pengerjaan lokal telah dikonfirmasi. Semua desain detail pada dokumen ini adalah usulan implementasi yang harus diputuskan sebelum migrasi M1. Bila bertentangan dengan ADR/ERD, hentikan coding, laporkan konflik, perbarui ADR/ERD dahulu, baru implementasi. Jangan membuat perilaku baru secara diam-diam.

## 1. Ringkasan

Modul ini bertanggung jawab atas identitas pengguna global, keanggotaan per sekolah, aktivasi akun, login host sekolah, login portal pusat, otorisasi RBAC berbasis tenant dan hubungan resource, pengelolaan sesi, serta audit akses. **Tenant = satu sekolah** dan satu pengguna dapat menjadi anggota lebih dari satu sekolah. Semua transaksi pembelajaran memerlukan membership yang sesuai dengan tenant dari hostname terverifikasi.

### 1.1 Sasaran keberhasilan

1. Pengguna sekolah hanya login dari hostname sekolah terkait; hostname lain tidak memberi sesi/otorisasi otomatis.
2. Siswa bisa login dengan username lokal dan password, tanpa email wajib.
3. Staf platform tidak mendapat akses otomatis ke data tugas/nilai/ujian sekolah.
4. Guru dapat memiliki dua membership dengan role berbeda tanpa saling mewarisi permission.
5. Perubahan status dan role efektif segera untuk permintaan baru; sesi yang terdampak dicabut/ditolak.
6. Semua jalur (HTTP, Livewire, download, job, ekspor) menggunakan pemeriksaan tenant dan permission yang dapat diuji.

### 1.2 Non-goals MVP

Registrasi publik siswa, SSO lintas sekolah, login Google/Microsoft, OAuth publik, MFA siswa, reset otomatis berbasis NISN, impersonasi akun, orang tua/wali, aplikasi mobile native, custom RBAC editor sekolah, domain kustom publik, pemulihan akun multi-tenant oleh Admin Sekolah.

## 2. Aktor, identitas, batas hak

| Aktor | Identitas | Lingkup autentikasi | Batas kewenangan |
|---|---|---|---|
| Platform Super Admin | `users` + `platform_staff` aktif | Host pusat eksplisit `admin.platform.test` | Provisioning tenant, status, paket, audit pusat; tidak otomatis dapat membaca data akademik |
| Admin Sekolah | `users` + `tenant_memberships` + `membership_roles` | Host sekolah aktif | Kelola membership, peran, profil sekolah sendiri; bukan password lintas tenant |
| Guru | `users` + membership + role `teacher` | Host sekolah aktif | Course yang terikat melalui `course_teachers` |
| Wali Kelas | Membership + role `homeroom_teacher` | Host sekolah aktif | Monitoring hanya rombel yang ditugaskan, bukan otomatis penilai |
| Siswa | Membership + role `student` | Host sekolah aktif | Course/enrollment dan hasil sendiri yang telah dipublikasikan |

`users` menyimpan kredensial global; `tenant_memberships` menyimpan username lokal, nama tampilan, status, dan afiliasi; `membership_roles` menautkan role tenant. **Tidak ada `role` tunggal di `users` atau cookie sebagai sumber keputusan final.**

## 3. Kontrak domain dan aturan lintas modul

- `TenantContext` diperoleh dari `tenant_domains.hostname` aktif, menggunakan host yang telah melalui trusted-host/trusted-proxy validation; **tidak** berasal dari `tenant_id` request.
- Host pusat dipisahkan dan didaftarkan eksplisit; tidak cocok dengan tenant mana pun. Host tak dikenal → 404/421.
- Central login **tidak** boleh memakai middleware tenant. Tenant login **harus** memverifikasi host, status tenant, user, membership, role, dan resource terkait.
- Account user global tidak otomatis dihapus ketika membership sebuah sekolah dinonaktifkan.
- Identifier lokal `local_username_normalized` unik per tenant, boleh identik antarsekolah; email global yang terverifikasi bersifat unik jika ada.
- Keseluruhan sistem berbasis *deny by default*. Permission diberikan hanya oleh rule server dan pada resource yang memang boleh diakses.
- Browser `SESSION_DOMAIN=null` atau host-only; cookie HttpOnly; Secure untuk HTTPS; SameSite sesuai risk assessment (awal Lax). CSRF aktif untuk mutasi.
- Staf platform tidak memiliki hak `tenant.*` hanya karena `platform_staff=active`. Jalur dukungan mengakses data siswa di luar MVP dan memerlukan ADR baru.
- Nama/kolom induk mengikuti ERD v1.0. Tabel tambahan pada §11 adalah **proposal perubahan ERD**, bukan izin membuat migration sepihak.

## 4. Login & autentikasi

### 4.1 AUTH-01 — Login per hostname sekolah (P0)

**Trigger:** pengguna mengakses `/login` pada `smpn10.platform.test` (contoh lokal). Form: `username`, `password`, opsi tampilan password, tautan bantuan akun. Tidak menampilkan pilihan sekolah pada host tenant.

**Alur sukses:**

1. Konfigurasi trusted proxy/host menerima hostname yang sah dan `ResolveTenantFromVerifiedHost` memastikan domain aktif dan terverifikasi.
2. Tenant harus berstatus `active`; `pending/suspended/archived` diarahkan ke halaman status terbatas, bukan fallback tenant lain.
3. Normalisasikan username lokal secara konsisten (trim + case policy; simpan asal dan versi normal). Cari membership berdasarkan `(tenant_id,local_username_normalized)`.
4. Cek user aktif, membership aktif, password hash valid, policy akun dan rate limit. Gunakan pesan gagal generik; jangan mengungkap apakah username terdaftar.
5. Regenerasi session ID; tetapkan session server `tenant_id`, `membership_id`, `user_id`, `auth_version` untuk konteks **host ini**; `TenantContext` selanjutnya divalidasi ulang dari sumber server.
6. Redirect menurut permission ke dashboard yang relevan; bila multi-role, gunakan prioritas dashboard yang terdokumentasi atau dashboard gabungan, bukan auto-eskalasi.
7. Catat `auth.login.succeeded`/`auth.login.failed` ke log aman tanpa password, token, atau isi identitas sensitif.

**Error:** unknown host (404/421), tenant inaktif (halaman layanan), salah kredensial (pesan generik), user/membership inaktif (pesan generik), throttled (429), user diwajibkan ganti password (halaman terbatas).

**Acceptance:** `users.id` yang sama berperan teacher di tenant A dan student di B; login A tidak memberi role/akses B. Username lokal sama di dua tenant dapat mengarah ke pengguna berbeda tanpa collision.

### 4.2 AUTH-02 — Login portal pusat (P0)

- Host hanya `admin.platform.test` (contoh); bukan login tenant.
- Gunakan email akun yang telah diverifikasi dan password; harus mempunyai `platform_staff.status=active` serta role `super_admin` (MVP).
- MFA wajib sebelum akses **production dengan data nyata**; di lokal gunakan test/dev mechanism, jangan menonaktifkan proteksi produksi dengan flag sembarang. Pilihan TOTP/recovery code dan implementasi final perlu spike keamanan sebelum go-live.
- Sesi pusat host-only dan terpisah; login sekolah tidak memberi hak pusat, sekalipun user sama.
- Route central harus memeriksa `EnsurePlatformStaff` dan permission spesifik per aksi; tanpa fallback membership.

### 4.3 AUTH-03 — Logout dan lifecycle sesi (P0)

- Logout POST ber-CSRF, invalidasi sesi host terkait, regenerate CSRF token, audit event.
- Session idle timeout dan absolute lifetime harus configurable (usulan awal: idle 30 menit admin, 120 menit guru/siswa; absolute max 12 jam) dan diuji UX; bukan ketentuan final.
- Saat `users.status != active`, membership suspended/left, role berubah, atau tenant suspended: request berikutnya diblokir; token sesi dibatalkan pada perubahan sensitif dengan session version/revoke strategy.
- Session cookie tidak memakai wildcard domain lintas-subdomain. Cross-host logout tidak diklaim otomatis.
- Login dan reset password dari host yang berbeda tidak boleh menginjeksi tenant aktif pada sesi sebelumnya.

### 4.4 AUTH-04 — Rate limiting & proteksi abuse (P0)

- Rate limit berlapis: IP + tenant + hash identifier untuk login, tambahan IP global untuk menghindari abuse antar-sekolah. Angka awal yang **diusulkan untuk diuji**, bukan jaminan: 5 gagal/menit/identifier, 20 gagal/menit/IP/tenant; adaptive cooldown dan pembatasan reset/aktivasi.
- Hindari *user enumeration* dari status HTTP/timing/pesan; gunakan hashing/check yang layak bagi identifier tidak dikenal jika diperlukan.
- Logging error bersifat structured dengan request ID; redaksi password, token, session cookie, dan PII. Proteksi brute-force tidak boleh menjadikan siswa terkunci permanen tanpa jalur pemulihan.

## 5. Aktivasi dan pemulihan akun

### 5.1 AUTH-05 — Pembuatan/undangan membership (P0)

Siswa/guru dibuat oleh Admin Sekolah yang memiliki permission. Admin memilih identitas baru atau mengundang akun yang sudah ada melalui verifikasi independen. Jangan menggabungkan `users` berdasar nama, NISN, atau email yang belum diverifikasi.

**A. Pengguna baru tanpa email (umum untuk siswa):**

1. Admin memasukkan `display_name`, username lokal unik, role awal, dan informasi profil minimum.
2. Sistem membuat user global dengan status sesuai alur aktivasi, membership `invited`, undangan/aktivasi bertoken acak kriptografis, disimpan hanya hash, punya batas waktu, serta single-use.
3. Admin menyerahkan kode/tautan sekali pakai via prosedur sekolah yang tervalidasi; tidak mengekspor daftar password permanen atau mencetak password yang dapat digunakan berulang.
4. Siswa membuka host sekolah → verifikasi kode → menetapkan password sendiri → akun dan membership menjadi aktif secara atomik.
5. Jika memakai password sementara sebagai fallback terotorisasi: `must_change_password=true`, rotasi wajib sebelum fitur belajar, dan distribusi di kanal resmi dengan masa berlaku singkat.

**B. Pengguna baru dengan email terverifikasi:** link aktivasi dipersempit ke tenant dan target invitation, token sekali pakai, email diverifikasi dengan proses yang sah. Jika email ternyata milik akun global yang sudah ada, **jangan** otomatis menempelkan membership.

**C. Pengguna existing (guru dua sekolah):** school admin membuat undangan pending; pemilik akun membuktikan kontrol akun sendiri (re-auth pada host tujuan + token undangan/kanal terverifikasi); server baru menautkan membership. Jika belum punya saluran verifikasi independen, jangan tautkan; masuk manual review platform. Sekolah tidak pernah melihat password global.

**Edge cases:** invitation expired, duplicate username, matching existing email, token reuse, token tenant A dipakai di B, admin dinonaktifkan sebelum accept, role diturunkan saat invite pending. Harus gagal aman dan teraudit.

### 5.2 AUTH-06 — Perubahan password dan reset (P0)

| Skenario | Pelaksana sah | Aturan |
|---|---|---|
| User aktif mengganti password sendiri | User yang re-auth atau mengonfirmasi sandi lama | Sandi global di-hash, sesi lain/semua host direvokasi sesuai strategi; audit |
| User email terverifikasi lupa password | Pemilik email via link reset kedaluwarsa, single-use | Respon generik; reset global menginvalidasi sesi; link tidak memberi akses tenant bila membership inaktif |
| Siswa tanpa email, hanya 1 membership | Admin Sekolah berpermission dan verifikasi identitas offline | **Boleh** memulai reset bantuan yang diaudit; token terikat ke user+tenant, tidak menampilkan password lama |
| User memiliki membership di 2+ tenant | Pemilik identitas via verifikasi independen / prosedur platform | Admin sekolah **dilarang** mereset password global, sekalipun salah satu membership nonaktif |
| User tidak punya email, punya 2+ tenant | Proses pemulihan platform khusus (manual, documented) | Tidak tersedia reset instan melalui admin sekolah MVP |
| Akun platform super admin | Proses staf pusat dengan MFA dan recovery terpisah | School admin tidak bisa melakukan tindakan apa pun terhadap kredensial staf |

Jumlah membership untuk policy reset dihitung **semua membership yang masih tercatat**, bukan hanya aktif, agar tidak bisa diakali dengan menonaktifkan membership sekolah lain. Bila suatu hari aturan berubah, perlu ADR dan threat model. Pengiriman dan penyimpanan token wajib hash, TTL, attempt limits, `consumed_at`, audit dan revokasi. Panjang password mengikuti kebijakan keamanan yang dapat diuji; hindari pembatasan panjang yang lemah dan password default sama bagi banyak siswa.

### 5.3 AUTH-07 — Suspend/deactivate (P0)

- Admin Sekolah: suspend/restore membership milik tenant sendiri dengan alasan; tidak menyentuh `users.status` global.
- Platform Super Admin: berwenang suspend user global hanya melalui proses akun keamanan khusus (jika requirement ditetapkan); tidak disamakan dengan operasi tenant.
- `left` adalah status keluar/historis, tidak menghapus nilai atau jawaban. Role dan assignment lama tetap ada untuk audit, tetapi tidak mengotorisasi request baru.
- Cegah penghapusan/suspend **admin sekolah aktif terakhir** kecuali ada pengganti aktif atau workflow pemulihan platform yang teraudit.

## 6. Membership, role, dan permission

### 6.1 AUTH-08 — Manage membership & assignment role (P0)

- Admin Sekolah dapat menambah anggota, undang ulang, ubah display name/username lokal (unik per tenant), berikan/cabut role sesuai izin, suspend/restore membership.
- Tidak bisa mengangkat diri sendiri/anggota menjadi platform staff, tidak bisa mengubah tenant_id membership, tidak bisa memberikan permission di luar katalog role.
- Membuat atau menghapus role system tidak tersedia di UI admin sekolah MVP; katalog dikelola versioned seed + migration.
- Role `homeroom_teacher` memberi hak monitoring terikat `classrooms.homeroom_teacher_profile_id` dan **tidak** otomatis hak `courses.update`/`grades.publish`.
- Perubahan role dilakukan dengan transaksi DB, invalidasi cache permission/session, dan audit before/after **role keys**, bukan membocorkan PII.

### 6.2 AUTH-09 — Permission catalog (P0)

Permission keys bersifat stabil. Daftar berikut baseline yang harus disinkronkan dengan modul domain ketika dibangun.

| Namespace | Permission minimal | Scope dan syarat tambahan |
|---|---|---|
| `school` | `school.view`, `school.update` | Tenant sendiri |
| `members` | `members.view`, `members.invite`, `members.update`, `members.suspend`, `members.assign_roles`, `members.assisted_reset` | Hanya same tenant; `assisted_reset` memakai policy khusus single-tenant |
| `academic` | `academic.manage`, `classrooms.view`, `classrooms.manage`, `subjects.manage`, `courses.manage` | Tidak bypass policy nilai/materi |
| `learning` | `learning.view`, `learning.create`, `learning.update`, `learning.publish` | Guru `course_teachers` aktif atau policy administratif eksplisit |
| `assignments` | `assignments.view`, `assignments.manage`, `submissions.create`, `submissions.view_own`, `submissions.grade` | Course enrollment/ownership/assignment rules |
| `quizzes` | `quizzes.manage`, `quizzes.take`, `quiz_results.view_own`, `quiz_results.grade` | Enrollment, status, waktu, attempt ownership |
| `grades` | `grades.view_course`, `grades.view_own_published`, `grades.publish`, `grades.view_homeroom_summary` | Teacher course, student own, homeroom assignment |
| `audit` | `audit.view_tenant` | Informasi yang boleh diketahui sekolah; tanpa audit platform |
| `platform` | `platform.tenants.view`, `platform.tenants.create`, `platform.tenants.update`, `platform.tenants.transition`, `platform.plans.assign`, `platform.audit.view` | **Hanya** staff pusat; tidak diwariskan dari membership sekolah |

### 6.3 Matriks role default MVP

Legenda: **Y** = hak role dasar *dan tetap wajib resource policy*; **N** = ditolak; **S** = scope/aturan spesifik.

| Aksi | Platform | School Admin | Teacher | Homeroom | Student |
|---|---|---|---|---|---|
| Provision tenant / assign plan | Y | N | N | N | N |
| Kelola anggota tenant | N | Y | N | N | N |
| Reset bantuan siswa single-tenant | N | S | N | N | N |
| Kelola tahun ajaran/rombel | N | Y | N | N | N |
| Buat/publish materi course | N | S* | S | N** | N |
| Membuat tugas / memberi nilai | N | S* | S | N** | N |
| Kumpulkan tugas / kerjakan kuis | N | N | N | N | S |
| Lihat grade terbit milik sendiri | N | N | N | N | S |
| Monitoring agregat wali kelas | N | S* | N** | S | N |
| Membaca data akademik tenant lain | N | N | N | N | N |

`*` Hanya jika kebijakan administratif eksplisit ditetapkan; default tidak mengedit isi pembelajaran. `**` Jika membership **juga** memiliki role teacher serta assignment course, izin berasal dari role/assignment teacher, bukan status homeroom.

### 6.4 AUTH-10 — Check authorization wajib (P0)

Keputusan akses adalah AND dari: trusted host, tenant active, authenticated user active, membership active, permission key, tenant ownership, dan hubungan domain (guru ditugaskan, siswa enrolled, grade published, rombel homeroom yang benar). Validasi di **Policy/Gate dan domain service**, bukan cuma menu/hide button. Untuk objek tenant lain respons 404 agar tidak mengungkap keberadaan record, sesuai risk review.

## 7. Middleware pipeline dan kontrak teknis

### 7.1 Urutan yang diwajibkan

| Jalur | Urutan / kontrak |
|---|---|
| Semua HTTP | Trusted hosts/proxies → request ID → CSRF/session normal dari stack Laravel → route group sesuai host |
| Tenant login (guest) | `ResolveTenantFromVerifiedHost` → `EnsureTenantActiveForLogin` → login throttling → credential lookup tenant |
| Tenant authenticated | `ResolveTenantFromVerifiedHost` → `EnsureTenantActive` → `auth:web` → `EnsureUserActive` → `EnsureActiveTenantMembership` → `EnsurePasswordChangedIfRequired` → `can:<ability>` / Policy → Controller/Livewire service |
| Central authenticated | `EnsureCentralHost` → `auth:web` → `EnsureUserActive` → `EnsurePlatformStaff` → `EnsureMfaSatisfied` (production) → `can:platform.*` → central service |
| Queue/CLI | No request host: **explicit trusted tenant context** from authorized dispatch; validate at execution; reset context in finally; no reuse leakage |

Urutan eksekusi middleware Laravel perlu dibuktikan lewat feature tests, karena route/model bindings dan session middleware dapat berubah menurut konfigurasi. **Jangan copy tabel ini sebagai `bootstrap/app.php` tanpa menguji urutan sebenarnya.**

### 7.2 Pseudocode keputusan aplikasi

```php
// Ilustrasi kontrak; bukan kode produksi yang boleh ditempel tanpa test.
$tenant = $tenantResolver->fromVerifiedHost($request->getHost());
$member = TenantMembership::query()
    ->where('tenant_id', $tenant->id)
    ->where('user_id', auth()->id())
    ->where('status', 'active')
    ->firstOrFail();

$context->bind($tenant->id, auth()->id(), $member->id);
$material = LearningMaterial::query()
    ->where('tenant_id', $context->tenantId())
    ->where('public_ulid', $publicUlid)
    ->firstOrFail();
Gate::authorize('update', $material); // cek guru assigned ke course ini
```

### 7.3 Route namespace (contoh desain)

```text
CENTRAL host:
  GET  /login                         [guest, central]
  POST /login                         [central-login-throttle]
  POST /logout                        [auth, central]
  GET  /dashboard                     [auth, platform-staff, MFA]

TENANT host:
  GET  /login                         [guest, resolved-tenant]
  POST /login                         [tenant-login-throttle]
  POST /logout                        [auth, active-member]
  GET  /activate/{opaque_token}       [tenant, throttle]
  POST /activate                      [tenant, throttle, CSRF]
  GET  /dashboard                     [auth, active-member]
  GET  /members                       [members.view]
  POST /members/invitations           [members.invite]
  PATCH /members/{public_ulid}/roles  [members.assign_roles]
  POST /members/{public_ulid}/suspend [members.suspend]
  POST /members/{public_ulid}/reset   [members.assisted_reset + strict policy]
```

Tidak ada resource ID internal atau tenant ID dari browser untuk menentukan pemilik data. Semua route binding dipersempit oleh context. Livewire action memanggil policy di server pada setiap mutasi; lifecycle component state tidak boleh menjadi bukti izin.

## 8. UI/UX halaman (P0)

| Halaman | Pengguna | Elemen dan aturan |
|---|---|---|
| Login Sekolah | Semua anggota sekolah | Logo/nama tenant, username, password, bantuan, error generik, versi mobile; tidak ada drop-down memilih tenant |
| Login Central | Staf platform | Email + password + MFA; branding platform, bukan sekolah |
| Set Password / Aktivasi | Undangan baru | Status token, set password/konfirmasi, ulang jika expired melalui admin; jangan tampilkan token ke analytics |
| Password Change | Pengguna login | Password lama (atau reauth), baru, konfirmasi; indikator kebijakan password dan sesi ter-revoke |
| Daftar Anggota | Admin Sekolah | Filter role/status; aksi hanya milik tenant; no cross-school search |
| Tambah Anggota | Admin Sekolah | Nama tampilan, username lokal, role, email opsional, pilihan akun baru vs existing verified |
| Detail Anggota | Admin Sekolah | Membership/status, role, audit ringkas, undang ulang/suspend; tidak pernah password plaintext |
| Akses Ditolak | Semua | 403 umum atau 404 objek tenant lain, link kembali yang aman |
| Layanan Tidak Aktif | User sekolah | Pesan status layanan yang netral; kontak admin, tanpa membocorkan detail billing |

Standar UI: bahasa Indonesia, WCAG 2.2 AA sebagai target, input berlabel, fokus keyboard terlihat, error terkait field, tidak menampilkan PII berlebihan. Semua tombol disable/hidden hanya UX, bukan kontrol akses.

## 9. Data, skema, dan perubahan ERD yang diperlukan

### 9.1 Tabel yang **sudah ada** pada ERD v1.0

`users`, `platform_staff`, `tenants`, `tenant_domains`, `tenant_memberships`, `roles`, `permissions`, `role_permissions`, `membership_roles`, `student_profiles`, `teacher_profiles`, `tenant_audit_logs`, `platform_audit_logs`.

### 9.2 **Usulan delta ERD** — wajib direview sebelum migration

| Tabel / kolom baru | Fungsi | Minimum constraint & keamanan | Milestone |
|---|---|---|---|
| `account_invitations` | Aktivasi dan penautan membership tertunda | `id`, `public_ulid`, `tenant_id`, `membership_id` nullable, `target_user_id` nullable, `token_hash` unique, `purpose`, `expires_at`, `consumed_at`, `created_by_membership_id`; composite FK untuk membership & inviter; token only hash; unique aktif per target/purpose via service | M1 |
| `account_recovery_requests` | Reset bantuan single-tenant dan recovery terverifikasi | `tenant_id` nullable utk platform, `user_id`, `initiated_by_user_id`, `purpose`, `token_hash`, `expires_at`, `consumed_at`, `verified_at`, `status`, audit; token hash unique; rate limit | M1 |
| `users.auth_version` / `users.sessions_revoked_at` | Tolak sesi lama setelah global password reset / account disable | integer meningkat atau timestamp monotonic; harus diterapkan konsisten ke session validation | M1 |
| `tenant_memberships.auth_version` (opsional) | Membatalkan sesi tenant akibat role/status berubah | Jika dipilih, diuji update atomic + cache invalidation; alternatif session registry | M1 |
| `account_security_events` (opsional) | Event security global bila audit tenant/platform tidak sesuai | Tidak duplikasi log tanpa retensi; tentukan pemilik data & akses | M1 review |

`account_invitations` dapat menunjuk membership `invited` yang sudah dibuat, bukan wajib membuat membership baru setelah klik token. Pastikan transaksi menerima undangan bisa commit/rollback atomik. Untuk invitation existing user, jangan membuat relasi `tenant_memberships.user_id` tanpa verifikasi identitas. Skema final diputuskan bersama ERD v1.1.

**Catatan implementasi:** framework bisa memiliki tabel `password_reset_tokens` dan `sessions`, tetapi tidak boleh menggantikan kebutuhan konteks tenant, audit, dan aturan reset multi-tenant tanpa desain adaptasi. Tetapkan pilihan penyimpanan session (`database`/Redis) saat M0 dan uji invalidasi global.

## 10. Event, audit, serta penanganan error

| Event | Lokasi audit | Field aman |
|---|---|---|
| `auth.login.succeeded/failed`, `auth.logout` | tenant/platform sesuai host | timestamp, request_id, verified tenant_id, actor ID bila ada, reason code generik |
| `identity.invitation.created/accepted/expired/revoked` | tenant audit | invitation public ID, actor ID, target membership ID, status; **bukan token** |
| `identity.password.changed/reset_requested/reset_completed` | tenant/platform dengan global security attribution | user ID, actor, mode, status, timestamp; **bukan password** |
| `membership.created/updated/suspended/restored` | tenant audit | actor, membership, role keys/status before-after |
| `role.changed` | tenant audit | actor, permission catalog version, membership |
| `platform.staff.session_revoked` | platform audit | actor, staff, reason |

Audit append-only; hak baca dibatasi; request ID tidak menyimpan form payload; log error yang mencakup identifier harus di-hash atau dimask sesuai kebutuhan monitoring.

## 11. Requirement backlog & acceptance criteria

| ID | Prioritas | Requirement | Acceptance test minimal |
|---|---|---|---|
| AUTH-01 | P0 | Login tenant by local username | Siswa tanpa email login; username sama A/B tidak bertabrakan |
| AUTH-02 | P0 | Login central hanya platform staff | Teacher di tenant tak boleh masuk dashboard platform |
| AUTH-03 | P0 | Host-only session + logout | Cookie A tidak berlaku B; sesi diregenerasi |
| AUTH-04 | P0 | Rate limit anti brute force | Identitas sama throttled, tak mengungkap user exists |
| AUTH-05 | P0 | Invitation + aktivasi sekali pakai | Expired/reused/wrong-tenant token ditolak |
| AUTH-06 | P0 | Password change/reset berkebijakan | School admin gagal reset guru multi-tenant |
| AUTH-07 | P0 | Membership suspend/restore | Suspended anggota langsung gagal operasi meski sesi lama |
| AUTH-08 | P0 | Assignment role per membership | Role tenant A tidak diwariskan ke B; no last-admin removal |
| AUTH-09 | P0 | Permission catalog stabil | Seed idempotent; unauthorized permission tidak dapat dibuat via UI |
| AUTH-10 | P0 | Resource policy server-side | Student A tidak baca file/grade milik B, guru tak edit course asing |
| AUTH-11 | P0 | Host & tenant middleware | Host unknown, pending, disabled-domain ditolak |
| AUTH-12 | P0 | Audit identity | Semua perubahan sensitif punya actor, tenant, request ID |
| AUTH-13 | P0 pre-prod | MFA platform + recovery | Staf pusat tanpa MFA ditolak pada production nyata |

### 11.1 Minimum automated tests (wajib)

```text
TenantHostTest::rejects_unknown_or_unverified_host
TenantAuthTest::allows_email_free_student_username_login
TenantAuthTest::same_username_in_two_tenants_resolves_separately
TenantAuthTest::global_user_roles_are_isolated_per_membership
TenantAuthTest::suspended_user_or_membership_fails
TenantAuthTest::host_only_cookie_prevents_cross_school_session
TenantAuthTest::forces_password_change_after_temporary_credentials
InvitationTest::wrong_tenant_expired_or_reused_token_is_rejected
InvitationTest::existing_user_not_auto_linked_by_email_or_name
PasswordRecoveryTest::school_admin_cannot_reset_multi_tenant_user
PasswordRecoveryTest::single_tenant_student_requires_approved_identity_check
PermissionTest::school_admin_cannot_grant_platform_role
PermissionTest::homeroom_cannot_grade_without_course_teacher_assignment
PermissionTest::teacher_cannot_edit_another_teacher_course
PermissionTest::student_cannot_view_another_students_grade_or_file
MembershipTest::last_active_school_admin_cannot_be_suspended
SessionTest::role_change_revokes_or_invalidates_old_permission
PlatformAuthTest::tenant_teacher_cannot_access_platform_dashboard
PlatformAuthTest::staff_cannot_read_school_grade_without_explicit_grant
TenantJobTest::resets_tenant_context_between_jobs
```

Gunakan setidaknya 3 tenant dummy, 2 username sama pada tenant berlainan, 1 guru dua tenant, 1 siswa tanpa email, 1 user suspended, dan 1 platform staff. Test database gunakan PostgreSQL (bukan semata SQLite) untuk constraint komposit.

## 12. NFR dan batas performa/keamanan

- OWASP multi-tenant isolation: tenant ID terverifikasi di query, cache, file, queue, audit; uji IDOR dan perubahan konteks.
- Password hash memakai Laravel Hash dengan algoritma/parameter terkonfigurasi, tidak encryption reversibel; token aktivasi/reset cryptographically secure, single-use, expire.
- Retensi token dan audit didefinisikan sebelum data nyata; tidak menyimpan data siswa aktual di seed/demo.
- Pengujian p95 login di staging dan uji beban autentikasi (termasuk rate limiter) dengan target kinerja yang ditetapkan di M0; hindari angka SLA yang belum diukur.
- Pengukuran login failed metrics tidak boleh membocorkan kredensial atau email siswa.
- Semua autentikasi dan aktivasi production hanya melalui HTTPS; HSTS ditetapkan setelah domain dan TLS stabil.

## 13. Dependensi, risiko, keputusan terbuka

| Item | Rekomendasi / tindakan |
|---|---|
| Framework auth | Pertimbangkan Laravel Fortify atau flow custom berbasis komponen Laravel; lakukan spike compatibility dengan tenant username sebelum memilih package |
| Permissions | Katalog `roles/permissions` milik ERD sebagai sumber data; jangan memasang package RBAC yang mengubah struktur tanpa ADR |
| Auth version/session registry | Putuskan strategi revokasi lintas-host sebelum M1 selesai |
| MFA platform | Wajib sebelum pilot data nyata; metode serta recovery perlu ADR/security spike |
| Identity linkage | Undangan verified, tidak otomatis merge berdasarkan email tidak diverifikasi; definisikan alternatif bila siswa tanpa email masuk sekolah kedua |
| Activation delivery | Pastikan SOP penyerahan kode offline oleh sekolah; risiko akses orang lain ke lembar undangan |
| Suspension policy | Jangan hapus data; legal retention dan lifecycle harus disahkan sebelum production |
| Database RLS | Tetap spike M1 sesuai ADR; jangan klaim aktif jika belum diuji koneksi/worker |

## 14. Urutan implementasi Codex (PR kecil)

1. **AUTH-S1:** migrations ERD central identity + composite FK/index/seed permission + test integritas. **Stop jika delta ERD §9 belum disahkan.**
2. **AUTH-S2:** trusted-host tenant resolver, central/tenant route boundaries, active tenant status, request context + negative host tests.
3. **AUTH-S3:** tenant login, central login, session/cookie, basic profile/password change, throttling dan tests.
4. **AUTH-S4:** roles, membership, policy contracts, administrative invitation service + tests.
5. **AUTH-S5:** activation, invitation, recovery single-tenant, cross-school identity linking verification + tests.
6. **AUTH-S6:** session revocation, Livewire security, tenant event audit, platform audit, threat tests.
7. **AUTH-S7:** MFA central staging/prod gate, smoke/load tests, RLS spike report.

**Definition of Done M1:** migrasi dan test jalan pada PostgreSQL lokal; 3 school host dummy; semua negative isolation tests lulus; log sanitization lulus; no implicit platform bypass; aktivitas sensitif teraudit; rekomendasi/keputusan terbuka dikunci sebelum go-live.

## 15. Struktur kode usulan

```text
app/
  Modules/Identity/{Actions,Contracts,DTO,Models,Policies,Services,Events}
  Modules/Tenancy/{Context,Resolver,Middleware,Services}
  Modules/Platform/{Auth,Policies,Services}
  Http/Middleware/{EnsureActiveTenantMembership,EnsurePlatformStaff,...}
  Policies/  # jika memakai auto-discovery Laravel untuk resource domain
routes/{central.php,tenant.php}
database/migrations/
database/seeders/{RolePermissionSeeder,DemoTenantSeeder}
tests/Feature/{Identity,Tenancy,Platform}
tests/Unit/{Identity,Authorization}
docs/{prd,adr,erd}
```

Folder ini hanya rekomendasi; sesuaikan dengan conventions repo dan jangan mengandalkan path untuk memutuskan authorization.

## 16. Instruksi untuk Codex (copy-paste saat mulai sprint)

```text
Implementasikan hanya scope AUTH-S<n> dalam PRD_Modul_Authentication_RBAC_v1.0.md.
Baca berurutan: PRD Master -> ADR-001 -> ERD v1.0 -> PRD Auth/RBAC -> PRD SaaS Central.
Jangan mengganti model users global + tenant_memberships, shared PostgreSQL, host-only sessions.
Jangan menambahkan kolom atau tabel dari bagian 'usulan delta ERD' sebelum ERD revisi disetujui.
Semua aksi tulis/read protected wajib TenantContext + Policy + negative cross-tenant tests.
Berikan daftar file diubah, skema dan constraint, tests yang dijalankan, risiko, serta hal yang belum diputuskan.
Gunakan fixture sintetis, bukan data sekolah/siswa sungguhan.
```

## 17. Referensi

- Dokumen internal: PRD Master v1.0; ADR-001 v1.0; ERD Core v1.0.
- Laravel 13 Authentication: https://laravel.com/docs/13.x/authentication
- Laravel 13 Authorization (Gates/Policies): https://laravel.com/docs/13.x/authorization
- Laravel 13 Email Verification: https://laravel.com/docs/13.x/verification
- Laravel 13 Password Reset: https://laravel.com/docs/13.x/passwords
- Laravel 13 Rate Limiting: https://laravel.com/docs/13.x/rate-limiting
- OWASP Multi-Tenant Security: https://cheatsheetseries.owasp.org/cheatsheets/Multi_Tenant_Security_Cheat_Sheet.html

## 18. Riwayat versi

| Versi | Tanggal | Perubahan |
|---|---|---|
| 1.0 | 2026-11-05 | Draf baseline autentikasi, membership, permission, middleware, recovery, audit, test, dan proposal delta ERD. |
