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
        <p>Akun: <strong>{{ $email }}</strong></p>
        <p>Secret manual: <code>{{ $secret }}</code></p>
        <p>URI authenticator: <code>{{ $otpauthUri }}</code></p>

        @if ($errors->has('code'))
            <p role="alert">{{ $errors->first('code') }}</p>
        @endif

        <form method="POST" action="{{ route('central.mfa.setup') }}">
            @csrf
            <label for="code">Kode 6 digit</label>
            <input id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" required>
            <button type="submit">Aktifkan MFA</button>
        </form>
    </main>
</body>
</html>
