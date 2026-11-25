# M1 ACCEPTANCE CHECKLIST

**Status 25 November 2026:** PR6 **Approved**, M1 **Closed by owner** atas approval akhir pengguna untuk lokal/sintetis.
**Evidence teknis belum seluruhnya lengkap:** browser empat host/HTTPS tetap belum dibuktikan. Approval tidak
mengubah poin yang belum teruji menjadi lulus, tidak menghapus insiden PR5, dan bukan izin produksi atau M2.
Baseline commit review: `b07ebdf182080f184065caf5b60a2b81c9efafaa` pada branch `Milestone-1`.
Commit remediasi yang disetujui: `015e792f350536db14f4be049624e6d0e82e95b2`; CI commit tersebut terverifikasi sukses.
Dokumentasi approval 25 November belum di-commit/push atau mempunyai CI sendiri.
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
- [x] Approval akhir PR6 dan penutupan M1 diberikan pengguna pada 25 November 2026 melalui percakapan.
- [ ] Seluruh evidence penerimaan teknis lengkap: demonstrasi browser empat host/HTTPS masih belum dibuktikan.
  Pemisahan record approval/evidence ini tidak menurunkan expected result atau memberi waiver pada acceptance baseline.

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
- [x] CI commit remediasi `015e792` lulus: [run 45356905502](https://github.com/Alfdhiw/LMS_SaaS/actions/runs/45356905502),
  job `smoke` dan `private-database-helper` sukses. Metadata diverifikasi 25 November; bukan CI perubahan approval ini.
- [ ] Uji manual 3 sekolah sintetis dalam 4 host lokal selesai.
- [x] README, CHANGELOG, AGENTS lokal, indeks, gate/checklist/matrix dan README contract diselaraskan.
  AGENTS tetap di-ignore sesuai aturan Git; sinkronisasi lokalnya bukan bukti dokumen tersebut dilacak Git.
- [x] Risiko MFA produksi, outbox, backup, RLS, dan data privasi tercatat; review memakai fixture sintetis saja.

## Bukti ringkas dan keputusan

Hasil remediasi 24 November: §8 [M1_FINAL_REVIEW.md](M1_FINAL_REVIEW.md); approval/CI terbaru 25 November: §9.

**Remediasi final:** full suite **100 test/791 assertion**, 311,85 detik; runtime terisolasi **2 test/51 assertion**;
Pint **134 file**; JavaScript **3 test**; HTTP smoke **6 pemeriksaan**; Composer valid. Semua lulus lokal.
Angka 75/513 dan runtime 2/43 di bawah hanya snapshot review awal. Tidak ada pengulangan suite aplikasi pada tugas
dokumentasi approval 25 November. Snapshot historis berikut tidak menggantikan evidence commit `015e792`:

- Full suite: `powershell -NoProfile -File scripts/m1-operator.ps1 test` — **75 test/513 assertion**, 324,09 detik.
- Runtime: `powershell -NoProfile -File tests/Support/m1-runtime-integration.ps1` — **2 test/43 assertion** pada tmpfs.
- JavaScript recovery: 3 test; Composer valid; Pint: 110 file; HTTP host smoke: 6 pemeriksaan.
- [CI commit b07ebdf](https://github.com/Alfdhiw/LMS_SaaS/actions/runs/45137773386) — kedua job sukses.
- Browser tidak dapat terhubung (`missing field sandboxPolicy`); tidak ada bukti baru browser/HTTPS.
- Approval PR0–PR5/baseline tetap berlaku; approval akhir PR6/M1 kemudian diberikan pengguna 25 November,
  bukan dibuat otomatis oleh Codex. Tidak ada pengecualian ADR atau izin M2.

**M1 COMPLETE hanya jika semua poin applicable di atas mempunyai evidence commit / screenshot sanitized / test log.**

Status **Closed by owner** mencatat keputusan pemilik pada 25 November, bukan klaim kriteria kelengkapan evidence
di atas sudah terpenuhi. Browser/HTTPS tetap follow-up terbuka, dan catatan historis kehilangan data tidak diubah.
