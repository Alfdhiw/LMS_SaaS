<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Terima undangan</title></head>
<body><main><h1>Terima undangan sekolah</h1>@if (! empty($error)) <p role="alert">{{ $error }}</p> @endif
<section><h2>Akun baru</h2><form method="post" action="/invitations/activate">@csrf <input type="hidden" name="_interface" value="web">
<label>ID undangan <input name="invitation_id" required maxlength="26"></label><label>Kode undangan <input name="token" required maxlength="64" autocomplete="off"></label>
<label>Password baru <input name="password" type="password" minlength="12" required autocomplete="new-password"></label>
<label>Ulangi password <input name="password_confirmation" type="password" minlength="12" required autocomplete="new-password"></label><button>Aktifkan akun</button></form></section>
<section><h2>Akun yang sudah ada</h2><form method="post" action="/invitations/join">@csrf
<label>ID undangan <input name="invitation_id" required maxlength="26"></label><label>Kode undangan <input name="token" required maxlength="64" autocomplete="off"></label>
<label>Email akun terverifikasi <input type="email" name="email" required autocomplete="username"></label><label>Password akun <input type="password" name="password" required autocomplete="current-password"></label><button>Hubungkan akun</button></form></section>
<p>Masukkan password hanya pada halaman sekolah ini; jangan berikan password kepada admin.</p></main></body></html>
