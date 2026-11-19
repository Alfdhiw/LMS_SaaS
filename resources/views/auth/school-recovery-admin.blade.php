<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="referrer" content="no-referrer"><meta name="csrf-token" content="{{ csrf_token() }}"><title>Verifikasi pemulihan siswa</title></head>
<body><main><h1>Verifikasi pemulihan siswa</h1>
<p>Periksa identitas siswa secara tatap muka sesuai SOP. Jangan meminta password atau rahasia browser siswa.</p>
<form id="lookup"><label>ID permintaan <input name="recovery_id" required minlength="26" maxlength="26"></label>
<button type="submit">Cari permintaan</button></form>
<section id="review" hidden><p>Nama: <strong id="name"></strong></p><p>Username sekolah: <strong id="username"></strong></p>
<form id="approve"><label><input type="checkbox" name="verified" required> Saya telah memverifikasi identitas siswa secara tatap muka.</label>
<button type="submit">Setujui permintaan</button></form></section>
<p id="message" role="status"></p><p><a href="/dashboard">Kembali ke dashboard</a></p></main>
<script>
(() => {
    let approvedId = null;
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const message = document.getElementById('message');
    const review = document.getElementById('review');
    document.getElementById('lookup').addEventListener('submit', async event => {
        event.preventDefault(); approvedId = null; review.hidden = true;
        try {
            const id = event.target.recovery_id.value.trim().toUpperCase();
            const result = await fetch('/members/recovery/' + encodeURIComponent(id), {credentials: 'same-origin', headers: {'Accept': 'application/json'}});
            if (!result.ok) throw new Error('Permintaan tidak tersedia.');
            const body = await result.json(); approvedId = body.data.recovery_id;
            document.getElementById('name').textContent = body.data.display_name;
            document.getElementById('username').textContent = body.data.username;
            document.getElementById('approve').reset(); review.hidden = false; message.textContent = '';
        } catch (error) { message.textContent = error.message; }
    });
    document.getElementById('approve').addEventListener('submit', async event => {
        event.preventDefault(); if (!approvedId || !event.target.verified.checked) return;
        try {
            const result = await fetch('/members/recovery/' + encodeURIComponent(approvedId) + '/approve', {
                method: 'POST', credentials: 'same-origin',
                headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
                body: JSON.stringify({identity_verified_offline: true})
            });
            if (!result.ok) throw new Error('Persetujuan gagal. Minta siswa membuat permintaan baru jika sudah kedaluwarsa.');
            approvedId = null; review.hidden = true;
            message.textContent = 'Permintaan disetujui. Siswa dapat membuat password pada perangkatnya.';
        } catch (error) { message.textContent = error.message; }
    });
})();
</script></body></html>
