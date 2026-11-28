<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Profil sekolah</title>
    <style>body{font:16px/1.5 system-ui;max-width:48rem;margin:2rem auto;padding:0 1rem}label{display:block;margin:1rem 0}input,select,button{font:inherit;padding:.45rem;max-width:100%}.errors{color:#9b1c1c}</style>
</head>
<body>
<main>
    <p><a href="/dashboard">Kembali ke dashboard</a></p>
    <h1>Profil sekolah</h1>
    @if (session('status')) <p role="status">{{ session('status') }}</p> @endif
    @if ($errors->any())
        <div class="errors" role="alert"><p>Periksa kembali isian berikut:</p><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    @if ($canUpdate)
    <form method="POST" action="{{ route('tenant.school.update') }}">
        @csrf
        <label>Nama sekolah <input name="name" required maxlength="200" value="{{ old('name', $profile->name) }}"></label>
        <label>Zona waktu <select name="timezone" required>
            @foreach (\DateTimeZone::listIdentifiers() as $zone)
                <option value="{{ $zone }}" @selected(old('timezone', $profile->timezone) === $zone)>{{ $zone }}</option>
            @endforeach
        </select></label>
        <label>Warna aksen (opsional, contoh #2454a4) <input name="accent_color" value="{{ old('accent_color', $profile->accentColor) }}" pattern="#[0-9a-fA-F]{6}" maxlength="7"></label>
        <button type="submit">Simpan profil</button>
    </form>
    @else
        <p>Nama: {{ $profile->name }}</p>
        <p>Zona waktu: {{ $profile->timezone }}</p>
        <p>Warna aksen: {{ $profile->accentColor ?? 'Belum diatur' }}</p>
    @endif
</main>
</body>
</html>
