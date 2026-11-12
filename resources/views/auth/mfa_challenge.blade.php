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

        @if (isset($error))
            <p role="alert">{{ $error }}</p>
        @endif

        @if ($errors->has('code'))
            <p role="alert">{{ $errors->first('code') }}</p>
        @endif

        <form method="POST" action="{{ $action }}">
            @csrf
            <label for="code">Kode MFA</label>
            <input id="code" name="code" autocomplete="one-time-code" required>
            <button type="submit">Verifikasi</button>
        </form>
    </main>
</body>
</html>
