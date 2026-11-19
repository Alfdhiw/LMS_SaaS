<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Lupa password</title></head>
<body><main><h1>Lupa password</h1><p>Masukkan email terverifikasi akun Anda.</p>
@if (! empty($status)) <p role="status">{{ $status }}</p> @endif
@if ($errors->any()) <p role="alert">{{ $errors->first() }}</p> @endif
<form method="post" action="/password/forgot">@csrf <label>Email <input type="email" name="email" required maxlength="254" autocomplete="email"></label><button>Kirim petunjuk</button></form>
<p><a href="/login">Kembali ke login</a></p></main></body></html>
