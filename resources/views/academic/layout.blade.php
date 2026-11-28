<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title')</title>
    <style>body{font:16px/1.5 system-ui;max-width:66rem;margin:2rem auto;padding:0 1rem;color:#10213c}label{display:inline-block;margin:.35rem .6rem .35rem 0}input,select,button{font:inherit;padding:.35rem;max-width:100%}section,article{border:1px solid #d5deeb;border-radius:.5rem;padding:1rem;margin:1rem 0}table{border-collapse:collapse;width:100%}td,th{padding:.4rem;border-bottom:1px solid #d5deeb;text-align:left}.error{color:#9b1c1c}form.inline{display:inline-block}@media(max-width:600px){table,tbody,tr,td,th{display:block}thead{display:none}td{border:0}}</style>
</head>
<body><main>
    <p><a href="/dashboard">Kembali ke dashboard</a></p>
    <h1>@yield('title')</h1>
    <p>Sekolah: {{ $schoolSlug }} · Tahun aktif: {{ $activeYear ?? 'Belum ditetapkan' }}</p>
    @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if ($errors->any())<div role="alert" class="error"><p>Periksa kembali isian:</p><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @yield('content')
</main></body>
</html>
