<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Undangan anggota</title></head>
<body><main><h1>Undangan anggota</h1><p><a href="/dashboard">Kembali ke dashboard</a></p>
@if ($errors->any()) <p role="alert">{{ $errors->first() }}</p> @endif
<section><h2>Akun baru tanpa email</h2>
<form method="post" action="/members/invitations">@csrf <input type="hidden" name="_interface" value="web">
<label>Nama <input name="name" required maxlength="150"></label>
<label>Username sekolah <input name="username" required maxlength="64"></label>
<label>Peran <select name="role"><option value="student">Siswa</option><option value="teacher">Guru</option></select></label>
<button type="submit">Buat undangan akun baru</button></form></section>
<section><h2>Undang pemilik akun yang sudah ada</h2><p>Penerima harus membuktikan kepemilikan akun sendiri.</p>
<form method="post" action="/members/invitations/existing">@csrf
<label>Username sekolah baru <input name="username" required maxlength="64"></label>
<label>Peran <select name="role"><option value="teacher">Guru</option><option value="student">Siswa</option></select></label>
<button type="submit">Buat undangan akun existing</button></form></section>
</main></body></html>
