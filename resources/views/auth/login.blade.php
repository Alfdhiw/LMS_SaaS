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
        @if (session('status')) <p role="status">{{ session('status') }}</p> @endif

        @if (isset($error))
            <p role="alert">{{ $error }}</p>
        @endif

        @if ($errors->has('credentials'))
            <p role="alert">{{ $errors->first('credentials') }}</p>
        @endif

        <form method="POST" action="{{ $action }}">
            @csrf
            <label for="identifier">{{ $identifierLabel }}</label>
            <input id="identifier" name="{{ $identifierName }}" value="{{ $value }}" required autocomplete="username">

            <label for="password">Password</label>
            <input id="password" name="password" type="password" required autocomplete="current-password">

            <button type="submit">Masuk</button>
        </form>
        @if ($identifierName === 'username')
            <p><a href="/password/forgot">Lupa password?</a> · <a href="/invitations/accept">Terima undangan sekolah</a></p>
            <p><a href="/recovery/school-assisted">Bantuan password siswa tanpa email</a></p>
        @endif
    </main>
</body>
</html>
