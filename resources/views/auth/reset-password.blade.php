<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>Buat password baru</title></head>
<body><main><h1>Buat password baru</h1>@if (! empty($error)) <p role="alert">{{ $error }}</p> @endif
<form method="post" action="/password/reset">@csrf
<input type="hidden" name="recovery_id" id="recovery-id"><input type="hidden" name="token" id="recovery-token">
<label>Password baru <input name="password" type="password" required minlength="12" autocomplete="new-password"></label>
<label>Ulangi password <input name="password_confirmation" type="password" required minlength="12" autocomplete="new-password"></label><button>Simpan password</button></form>
<p>Jika tautan tidak dapat digunakan, mintalah tautan baru.</p></main>
<script>const p = new URLSearchParams(location.hash.slice(1)); document.getElementById('recovery-id').value = p.get('id') || ''; document.getElementById('recovery-token').value = p.get('token') || ''; history.replaceState(null, '', location.pathname);</script>
</body></html>
