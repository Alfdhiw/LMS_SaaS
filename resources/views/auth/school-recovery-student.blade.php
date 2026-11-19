<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="referrer" content="no-referrer"><meta name="csrf-token" content="{{ csrf_token() }}"><title>Bantuan password siswa</title></head>
<body><main><h1>Bantuan password siswa tanpa email</h1>
<p>Gunakan perangkat dan sesi privat. Minta petugas sekolah memverifikasi identitas Anda secara tatap muka.
Jangan berikan password atau rahasia browser kepada petugas. Jangan tutup atau muat ulang halaman sampai selesai.</p>
<p>Fitur ini memerlukan HTTPS dan browser yang mendukung Web Crypto. Jika perangkat tidak tersedia, hubungi sekolah.</p>
<form id="start"><label>Username sekolah <input name="username" maxlength="64" required autocomplete="username"></label>
<button type="submit">Buat permintaan</button></form>
<section id="finish" hidden><p>Tunjukkan hanya ID permintaan ini kepada petugas: <strong id="request-id"></strong></p>
<p>Setelah identitas Anda diverifikasi dan permintaan disetujui, buat password baru di sini.</p>
<form id="reset"><label>Password baru <input name="password" type="password" required minlength="12" maxlength="1024" autocomplete="new-password"></label>
<label>Ulangi password <input name="password_confirmation" type="password" required minlength="12" maxlength="1024" autocomplete="new-password"></label>
<button type="submit">Simpan password</button></form></section>
<p id="message" role="status"></p><p><a href="/login">Kembali ke login</a></p></main>
<script>
(() => {
    let secret = null, requestId = null;
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const message = document.getElementById('message');
    const hex = bytes => Array.from(new Uint8Array(bytes), n => n.toString(16).padStart(2, '0')).join('');
    async function post(url, body) {
        const result = await fetch(url, {method: 'POST', credentials: 'same-origin', headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf}, body: JSON.stringify(body)});
        if (!result.ok) throw new Error('Permintaan belum dapat diproses. Hubungi petugas atau coba lagi.');
        return result.json();
    }
    document.getElementById('start').addEventListener('submit', async event => {
        event.preventDefault();
        if (!window.isSecureContext || !window.crypto?.subtle) { message.textContent = 'Gunakan HTTPS dan browser yang mendukung Web Crypto.'; return; }
        try {
            const bytes = new Uint8Array(32); crypto.getRandomValues(bytes);
            const candidate = hex(bytes);
            const commitment = hex(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(candidate)));
            const result = await post('/recovery/school-assisted/request', {username: event.target.username.value, commitment});
            secret = candidate; requestId = result.recovery_id;
            document.getElementById('request-id').textContent = requestId;
            document.getElementById('finish').hidden = false;
            document.getElementById('start').hidden = true;
            message.textContent = 'Minta petugas memverifikasi identitas Anda.';
        } catch (error) { message.textContent = error.message; }
    });
    document.getElementById('reset').addEventListener('submit', async event => {
        event.preventDefault();
        if (!secret || !requestId) { message.textContent = 'Buat permintaan baru pada perangkat ini.'; return; }
        try {
            await post('/recovery/school-assisted', {recovery_id: requestId, token: secret, password: event.target.password.value, password_confirmation: event.target.password_confirmation.value});
            secret = null; requestId = null; event.target.reset(); document.getElementById('finish').hidden = true;
            message.textContent = 'Password berhasil diperbarui. Silakan masuk kembali.';
        } catch (error) { message.textContent = error.message; }
    });
})();
</script></body></html>
