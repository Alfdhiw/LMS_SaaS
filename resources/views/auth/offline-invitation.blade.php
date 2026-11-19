<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Kode undangan</title><meta name="referrer" content="no-referrer"></head>
<body><main><h1>Kode undangan sekali pakai</h1>
<p>Serahkan kode ini hanya kepada penerima yang identitasnya telah diverifikasi, melalui saluran sekolah yang aman. Kode tidak ditampilkan kembali.</p>
<dl><dt>Alamat sekolah</dt><dd>{{ $host }}/invitations/accept</dd><dt>ID undangan</dt><dd><code>{{ $issued['invitation_id'] }}</code></dd><dt>Kode undangan</dt><dd><code>{{ $issued['token'] }}</code></dd><dt>Jenis</dt><dd>{{ $kind === 'new' ? 'Akun baru' : 'Akun existing' }}</dd></dl>
<p>Jangan simpan kode dalam log, dokumen bersama, atau pesan grup. Masa berlaku 72 jam.</p>
<p><a href="/members/manage">Selesai</a></p></main></body></html>
