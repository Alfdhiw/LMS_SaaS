<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Aktivasi Admin Sekolah</title>
</head>
<body>
    <main>
        <h1>Aktivasi Admin Sekolah</h1>
        <p>{{ $tenantName }}</p>
        <p>Halo, {{ $displayName }}. Tetapkan password untuk mengaktifkan akun admin sekolah.</p>

        @if ($errors->any())
            <p role="alert">{{ $errors->first() }}</p>
        @endif

        <form method="POST" action="{{ $action }}">
            @csrf
            <label for="password">Password baru</label>
            <input id="password" name="password" type="password" required minlength="12" autocomplete="new-password">

            <label for="password_confirmation">Ulangi password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required minlength="12" autocomplete="new-password">

            <button type="submit">Aktifkan akun</button>
        </form>
    </main>
</body>
</html>
