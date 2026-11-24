<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Tenant Management') - LMS SaaS</title>
    <style>
        :root { color-scheme: light; font-family: Inter, ui-sans-serif, system-ui, sans-serif; color: #14213d; background: #f3f6fb; }
        * { box-sizing: border-box; }
        body { margin: 0; }
        header { background: #14213d; color: #fff; padding: 1rem 5vw; display: flex; justify-content: space-between; gap: 1rem; align-items: center; }
        header a, header button { color: #fff; }
        nav { display: flex; gap: 1rem; align-items: center; }
        main { width: min(1120px, 90vw); margin: 2rem auto 4rem; }
        h1, h2 { margin-top: 0; }
        .grid { display: grid; grid-template-columns: minmax(280px, 360px) 1fr; gap: 1.25rem; align-items: start; }
        .card { background: #fff; border: 1px solid #dce3ef; border-radius: 14px; padding: 1.25rem; box-shadow: 0 8px 24px rgb(20 33 61 / 6%); }
        label { display: block; font-weight: 650; margin-top: .85rem; }
        input, select, button { font: inherit; }
        input { width: 100%; border: 1px solid #b9c5d8; border-radius: 8px; padding: .65rem .7rem; margin-top: .35rem; }
        input[type=checkbox] { width: auto; margin-right: .35rem; }
        button, .button { display: inline-block; border: 0; border-radius: 8px; padding: .62rem .85rem; background: #2563eb; color: #fff; cursor: pointer; text-decoration: none; }
        button.secondary, .button.secondary { background: #475569; }
        button.danger { background: #b42318; }
        .inline { display: inline; }
        .actions { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: 1rem; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; vertical-align: top; border-bottom: 1px solid #e6ebf2; padding: .75rem .5rem; }
        th { color: #475569; font-size: .86rem; }
        code { overflow-wrap: anywhere; }
        .status { display: inline-block; border-radius: 999px; padding: .2rem .55rem; background: #e2e8f0; font-size: .82rem; }
        .status.active { background: #dcfce7; color: #166534; }
        .status.pending { background: #fef3c7; color: #92400e; }
        .status.suspended, .status.archived { background: #fee2e2; color: #991b1b; }
        .notice { border-radius: 10px; padding: .8rem 1rem; margin-bottom: 1rem; background: #e0f2fe; color: #075985; }
        .error { background: #fee2e2; color: #991b1b; }
        .muted { color: #64748b; }
        dl { display: grid; grid-template-columns: 180px 1fr; gap: .55rem 1rem; }
        dt { font-weight: 650; }
        dd { margin: 0; overflow-wrap: anywhere; }
        @media (max-width: 800px) { .grid { grid-template-columns: 1fr; } dl { grid-template-columns: 1fr; gap: .2rem; } dd { margin-bottom: .65rem; } table { display: block; overflow-x: auto; white-space: nowrap; } }
    </style>
</head>
<body>
<header>
    <strong>LMS SaaS · Central</strong>
    <nav>
        <a href="{{ route('central.password.change') }}">Ganti password</a>
        @php
            $centralUser = request()->attributes->get('central_user');
            $platformPermissions = app(\App\Modules\Platform\Services\PlatformPermissionChecker::class);
        @endphp
        @if ($centralUser && $platformPermissions->allows($centralUser, 'platform.tenants.view'))
            <a href="{{ route('central.dashboard') }}">Dashboard</a>
            <a href="{{ route('central.tenants.index') }}">Tenant directory</a>
        @endif
        @if ($centralUser && $platformPermissions->allows($centralUser, 'platform.audit.view'))
            <a href="{{ route('central.audit.index') }}">Audit platform</a>
        @endif
        <form class="inline" method="POST" action="{{ route('central.logout') }}">
            @csrf
            <button class="secondary" type="submit">Keluar</button>
        </form>
    </nav>
</header>
<main>
    @if (session('status'))
        <div class="notice" role="status">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="notice error" role="alert">{{ $errors->first() }}</div>
    @endif
    @yield('content')
</main>
</body>
</html>
