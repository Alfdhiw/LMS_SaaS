# Runbook Central MFA M1

## Tujuan

Runbook ini mengatur enrollment, login, recovery, dan penanganan kehilangan perangkat MFA untuk operator portal central. Gunakan hanya akun sintetis pada development; jangan memasukkan email, secret, recovery code, atau data siswa nyata ke test.

## Prasyarat production

- Portal central hanya tersedia melalui `SAAS_CENTRAL_HOST` dan HTTPS.
- `APP_ENV=production` membuat MFA central wajib secara fail-closed.
- `APP_KEY` harus stabil dan tersimpan di secret manager. Secret TOTP di database dienkripsi dengan application key.
- Session backend production harus server-side dan host-only; jangan mengatur `SESSION_DOMAIN` lintas host.
- Migration `2026_11_09_000500_create_platform_mfa_credentials` harus berstatus `Ran`.

## Enrollment operator

1. Buka `https://<central-host>/login`.
2. Login menggunakan email terverifikasi milik `platform_staff` aktif.
3. Jika belum enrolled, sistem mengarahkan ke `/mfa/setup`.
4. Tambahkan secret atau URI `otpauth://` ke authenticator yang disetujui.
5. Masukkan kode TOTP 6 digit yang sedang aktif.
6. Simpan recovery code di password manager/secret vault terpisah. Recovery code ditampilkan satu kali dan tidak dicatat di audit log.
7. Logout, login ulang, dan verifikasi challenge TOTP.

Jangan mengirim secret, URI, kode TOTP, atau recovery code melalui chat, issue tracker, screenshot, atau log.

## Login dan recovery

- Login production terdiri dari password lalu challenge MFA.
- Kode TOTP memiliki window waktu terbatas dan tidak boleh dipakai ulang pada counter yang sama.
- Recovery code dapat digunakan satu kali. Setelah digunakan, jumlah code tersisa berkurang.
- Kegagalan login/MFA di-rate-limit dan dicatat sebagai event audit tanpa password, token, secret, atau kode.

## Kehilangan perangkat atau reset/re-enrollment

Reset bukan self-service. Operator harus melakukan verifikasi identitas dan incident response di luar aplikasi terlebih dahulu.
Setelah disetujui, super admin aktif yang memiliki permission `platform.staff.mfa_reset` dapat memanggil endpoint berikut dari host central:

```text
POST /security/mfa/{target_public_ulid}/reset
reason=device_lost|suspected_compromise|scheduled_reenrollment
```

Operasi ini hanya menerima target `platform_staff` aktif yang sudah memiliki credential MFA. Secara atomik sistem akan:

1. Menghapus secret TOTP dan seluruh recovery code hash lama.
2. Menaikkan `users.auth_version` agar sesi target pada semua host ditolak pada request berikutnya.
3. Mencatat `auth.mfa.reset` ke `platform_audit_logs` dengan actor, request ID, reason code, dan target identifier yang di-hash.

Endpoint tidak menerima secret, recovery code, password, atau alasan bebas dari client. Target yang tidak valid atau belum enrolled
dikembalikan sebagai 404 agar tidak membocorkan status credential.

Pada login berikutnya, dengan `SAAS_CENTRAL_MFA_REQUIRED=true` atau environment production, target diarahkan ke `/mfa/setup`.
Enrollment baru membuat recovery code baru dan mencatat `auth.mfa.enrolled`; recovery code lama tidak dapat digunakan lagi.
Simpan hasil enrollment di password manager/secret vault dan jangan menyalinnya ke log atau tiket.

## Development smoke test

Gunakan akun dan email sintetis. Untuk menguji alur MFA di local, set `SAAS_CENTRAL_MFA_REQUIRED=true`, clear config cache, lalu buka host central yang dipetakan ke `127.0.0.1`. Jangan menyalin secret atau recovery code ke repository.

Evidence otomatis yang wajib disimpan adalah hasil `M1AuthRbacTest`, bukan nilai secret atau code.

## Audit dan observability

Event yang relevan:

- `auth.login.mfa_pending`
- `auth.mfa.enrolled`
- `auth.mfa.reset`
- `auth.mfa.failed`
- `auth.mfa.verified`

Audit hanya boleh berisi alasan dan identifier yang telah di-hash. Periksa alert untuk percobaan MFA berulang, perubahan status `platform_staff`, dan perubahan credential MFA.
