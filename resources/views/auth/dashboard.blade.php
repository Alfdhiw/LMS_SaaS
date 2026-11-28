<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
</head>
<body>
    <main>
        <h1>{{ $title }}</h1>
        <p>{{ $subtitle }}</p>
        <p>Selamat datang, {{ $userName }}.</p>
        <p><a href="/school/profile">Profil sekolah</a></p>
        @if ($manageAcademic ?? false)
            <p><a href="/academic/periods">Tahun ajaran dan semester</a></p>
            @if ($viewMembers ?? false)
                <p><a href="/academic/profiles/teacher">Profil guru</a> · <a href="/academic/profiles/student">Profil siswa</a></p>
            @endif
        @endif
        @if ($manageSubjects ?? false)<p><a href="/academic/subjects">Mata pelajaran</a></p>@endif
        <p><a href="/password/change">Ganti password</a></p>
        @if ($manageMembers ?? false)
            <p><a href="/members/manage">Kelola undangan anggota</a></p>
        @endif
        @if ($manageRecovery ?? false)
            <p><a href="/members/recovery">Verifikasi pemulihan siswa</a></p>
        @endif

        @if (! empty($recoveryCodes ?? []))
            <section>
                <h2>Simpan recovery code</h2>
                <p>Setiap kode hanya dapat digunakan satu kali. Kode ini tidak akan ditampilkan lagi.</p>
                <ul>
                    @foreach ($recoveryCodes as $recoveryCode)
                        <li><code>{{ $recoveryCode }}</code></li>
                    @endforeach
                </ul>
            </section>
        @endif

        <form method="POST" action="{{ $logoutAction }}">
            @csrf
            <button type="submit">Keluar</button>
        </form>
    </main>
</body>
</html>
