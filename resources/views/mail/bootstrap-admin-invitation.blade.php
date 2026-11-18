<!doctype html>
<html lang="id">
<body>
    <h1>Aktivasi admin sekolah</h1>
    <p>Halo, {{ $displayName }}.</p>
    <p>Akun admin untuk <strong>{{ $tenantName }}</strong> sudah disiapkan.</p>
    <p><a href="{{ $activationUrl }}">Aktifkan akun admin</a></p>
    <p>Tautan ini kedaluwarsa pada {{ $expiresAt }} dan hanya dapat digunakan satu kali.</p>
    <p>Jika Anda tidak mengharapkan email ini, abaikan pesan ini dan hubungi operator platform.</p>
</body>
</html>
