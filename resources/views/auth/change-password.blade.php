<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Ganti password — LMS SaaS</title>
</head>
<body>
<main>
    <h1>Ganti password</h1>
    <p>Password berlaku untuk akun global Anda. Setelah berhasil, semua sesi di seluruh sekolah dan portal pusat dicabut.</p>
    <p>Gunakan 12–128 karakter. Jangan memakai kembali password saat ini.</p>
    <p>Batas penyimpanan password: {{ $passwordByteLimit }} byte. Huruf non-ASCII dapat memakai lebih dari satu byte.</p>
    @if ($errors->any())
        <p role="alert">{{ $errors->first() }}</p>
    @endif
    <form method="POST" action="{{ $central ? route('central.password.change.update') : route('tenant.password.change.update') }}">
        @csrf
        <p><label for="current-password">Password saat ini</label><br>
            <input id="current-password" name="current_password" type="password" required autocomplete="current-password" maxlength="1024"></p>
        <p><label for="new-password">Password baru</label><br>
            <input id="new-password" name="password" type="password" required autocomplete="new-password" minlength="12" maxlength="128"></p>
        <p><label for="confirm-password">Konfirmasi password baru</label><br>
            <input id="confirm-password" name="password_confirmation" type="password" required autocomplete="new-password" minlength="12" maxlength="128"></p>
        <button type="submit">Ganti password dan keluar dari semua sesi</button>
    </form>
    <p><a href="/dashboard">Kembali ke dashboard</a></p>
</main>
</body>
</html>
