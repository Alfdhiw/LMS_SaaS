# M1 ACCEPTANCE CHECKLIST

**Status 24 November 2026:** remediasi F01–F03 diimplementasikan dengan izin pengguna; PR6/M1 belum ditutup.
Baseline commit review: `b07ebdf182080f184065caf5b60a2b81c9efafaa` pada branch `Milestone-1`.
Checklist ini mencakup working tree remediasi yang belum di-commit/push; CI baseline bukan CI kode baru.
Evidence lengkap dan batas pengujian: [M1_FINAL_REVIEW.md](M1_FINAL_REVIEW.md).
`[x]` berarti bukti untuk poin yang disebut tersedia; `[ ]` berarti belum lengkap, termasuk poin parsial.
Kelulusan suite tidak otomatis mencentang semua requirement. Tidak ada approval manusia yang dibuat oleh checklist ini.

## Gate review

- [x] ADR-001 disetujui, revisi tercatat. Approval baseline 8 November di architecture gate.
- [x] ADR-002 disetujui, `app/Modules` satu-satunya namespace modul bisnis; kepatuhan boundary dinilai terpisah di bawah.
- [x] ERD v1.1 disetujui, dokumentasi indeks diperbarui.
- [x] Tidak ada migration bawaan Laravel konflik dengan `users` M1: hanya empat batch M1 dan satu migration MFA.
- [ ] Semua pekerjaan M1 dikerjakan pada branch baru, tanpa menghapus data M0. Branch benar, tetapi klaim historis
  tanpa penghapusan tidak dapat dicentang: insiden PR5 dan rebuild berizin 19 November tetap tercatat di gate.
- [x] Write M1 dipanggil melalui contract/action pemilik, Support tidak mengimpor Modules; `M1ArchitectureTest`
  menjaga dependency/write dengan parser AST. Tidak merupakan analisis semantik menyeluruh seluruh bentuk PHP.
- [ ] PR6 dan penutupan M1 disetujui pengguna setelah seluruh temuan applicable ditangani.

## Schema & role catalogue

- [x] 15 tabel core M1, tambahan MFA dan migrations terpasang; `M1DatabaseContractTest` memeriksa constraint/index
  yang dideklarasikan migration serta 95 constraint tervalidasi. Negatif kritis termasuk FK/scope/primary/XOR diuji;
  ini verifikasi implementasi M1 saat ini, bukan fuzzing exhaustive setiap kombinasi input ERD.
- [x] Migration up/down pada PostgreSQL test diverifikasi melalui `DatabaseMigrations` di tes concurrency;
  bootstrap database kosong juga diuji oleh integrasi runtime terisolasi. Bukan rollback database utama.
- [x] Role+permission seeded idempotent, permission platform tidak bisa di-grant tenant role (`M1RolePermissionSeederTest`).
- [x] Composite FK tenant guard diuji negatif (`M1DatabaseContractTest`).
- [x] Tidak ada role platform di membership roles: katalog empat role sekolah dan grant permission platform ditolak.

## Host & session

- [x] `admin.platform.test` dan tiga host sekolah terpisah pada feature tests dan enam GET smoke; bukan demo login browser.
- [x] Unknown/revoked host ditolak tanpa fallback (`M1HostContextTest`).
- [x] Konfigurasi sesi lokal diverifikasi host-only secara read-only; pengikatan sesi host/tenant diuji
  `M1AuthRbacTest`. Perilaku cookie browser riil belum diuji.
- [x] Middleware protected request membandingkan auth version global+membership; tes revokasi yang tersedia lulus.
  Kelengkapan skenario multi-host/status tetap mengikuti matrix, bukan klaim semua kombinasi telah diuji.
- [x] Logout POST+CSRF, invalidasi sesi dan regenerate token diperiksa pada kode dan `M1AuthRbacTest`.

## Identity & Recovery

- [x] Login tenant berdasarkan username tenant, password user global (`M1AuthRbacTest`).
- [x] Login pusat hanya untuk staff active dan email terverifikasi; enrollment/reset MFA juga diuji.
- [x] Undangan new user (termasuk tanpa email) single-use, bound to tenant, expire. Host/replay akun baru,
  expiry bootstrap dan undangan anggota biasa diuji (`M1AuthRbacTest`, `M1ProvisioningTest`, `M1SecurityClosureTest`).
- [x] Join-existing mewajibkan undangan host-bound dan re-auth (`M1IdentityFlowsTest`).
- [x] `school_assisted` ditolak untuk 2+ membership historis atau platform staff; alur dua sesi diuji.
- [x] Reset menaikkan `users.auth_version`; perubahan role/status menaikkan version membership.
- [x] User aktif dapat mengganti password sendiri dengan verifikasi sandi lama, CSRF/MFA/host/rate guard, audit
  atomik dan revokasi sesi global (`M1PasswordChangeTest`). `must_change_password` dibatasi pada perubahan/logout.
- [x] Aksi sensitif M1 yang tersedia menggunakan audit/redaction, termasuk perubahan password dan mail failure
  dengan kode stabil tanpa exception transport. Tes key/redaction bukan deteksi semua secret pada nilai bebas.

## Provisioning & UI

- [x] Create tenant atomik: pending + default host + settings + invitation + audit. `M1SecurityClosureTest`
  menginjeksi kegagalan invitation setelah write bundle dan memeriksa rollback bisnis (M1-PROV04).
- [x] Permintaan identik actor/key/digest tidak membuat tenant ganda; dua proses diuji oleh `M1ProvisioningConcurrencyTest`.
- [x] Mismatch digest -> conflict, collision slug/domain/reserved central slug -> tidak ada bundle parsial.
  Record idempotency/audit kegagalan tetap diperbolehkan sesuai kontrak.
- [x] Notification gagal bisa diulang tanpa reprovisioning. Kegagalan transport -> audit aman -> resend dan rotasi
  token diuji langsung (M1-PROV05); durable outbox belum ditambahkan.
- [x] Pending menolak login umum dan hanya mengizinkan activation purpose-limited; modul akademik belum tersedia.
- [x] Activate explicit setelah readiness; suspend menolak request tenant. Readiness/concurrency serta replay sesi
  protected lama sesudah suspend/role/status diuji; tenant lain tetap dapat diakses.
- [x] Dashboard Super Admin: directory, detail, status, agregat, audit dengan permission dan field terpilih;
  `M1PlatformDashboardTest` lulus. Bukan audit privasi untuk seluruh fitur masa depan.

## Verification

- [x] `M1DatabaseContractTest` lulus PostgreSQL (9 test dalam full suite).
- [x] Skenario otomatis HTTP/security M1_TEST_MATRIX untuk scope M1 lulus, termasuk concurrency, fault injection,
  replay sesi dan queue sync. Batas per skenario tetap tercatat; bukan bukti browser atau policy akademik M2.
- [x] Semua tests M0 tetap lulus (3 test dalam full suite).
- [x] Pint lokal 134 file lulus; Composer valid dan JavaScript recovery 3 test lulus.
- [ ] CI commit remediasi lulus. CI baseline `b07ebdf` sukses pada kedua job; perubahan kode/dokumentasi baru
  belum di-commit/push atau diuji CI tersendiri.
- [ ] Uji manual 3 sekolah sintetis dalam 4 host lokal selesai.
- [x] README, CHANGELOG, AGENTS lokal, indeks, gate/checklist/matrix dan README contract diselaraskan.
  AGENTS tetap di-ignore sesuai aturan Git; sinkronisasi lokalnya bukan bukti dokumen tersebut dilacak Git.
- [x] Risiko MFA produksi, outbox, backup, RLS, dan data privasi tercatat; review memakai fixture sintetis saja.

## Bukti ringkas dan keputusan

Hasil terbaru remediasi: lihat §8 [M1_FINAL_REVIEW.md](M1_FINAL_REVIEW.md). Ringkasan berikut adalah snapshot
sebelum perbaikan, dipertahankan sebagai riwayat dan tidak dihitung sebagai CI working tree baru:

**Remediasi final:** full suite **100 test/791 assertion**, 311,85 detik; runtime terisolasi **2 test/51 assertion**;
Pint **134 file**; JavaScript **3 test**; HTTP smoke **6 pemeriksaan**; Composer valid. Semua lulus lokal.
Angka 75/513 dan runtime 2/43 di bawah hanya snapshot review awal.

- Full suite: `powershell -NoProfile -File scripts/m1-operator.ps1 test` — **75 test/513 assertion**, 324,09 detik.
- Runtime: `powershell -NoProfile -File tests/Support/m1-runtime-integration.ps1` — **2 test/43 assertion** pada tmpfs.
- JavaScript recovery: 3 test; Composer valid; Pint: 110 file; HTTP host smoke: 6 pemeriksaan.
- [CI commit b07ebdf](https://github.com/Alfdhiw/LMS_SaaS/actions/runs/45137773386) — kedua job sukses.
- Browser tidak dapat terhubung (`missing field sandboxPolicy`); tidak ada bukti baru browser/HTTPS.
- Approval PR0–PR5/baseline yang sudah ada tetap berlaku. Tidak ada approval otomatis PR6, pengecualian ADR, atau M2.

**M1 COMPLETE hanya jika semua poin applicable di atas mempunyai evidence commit / screenshot sanitized / test log.**
