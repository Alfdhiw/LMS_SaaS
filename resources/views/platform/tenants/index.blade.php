@extends('platform.layout')

@section('title', 'Tenant directory')

@section('content')
<h1>Tenant directory</h1>
<p class="muted">Kelola onboarding dan lifecycle sekolah tanpa membuka data akademik atau data siswa.</p>

<div class="grid">
    @if (app(\App\Modules\Identity\Services\PlatformPermissionChecker::class)->allows(request()->attributes->get('central_user'), 'platform.tenants.create'))
    <section class="card">
        <h2>Provision tenant</h2>
        <p class="muted">Tenant baru dibuat sebagai <strong>pending</strong> sampai admin mengaktifkan akun dan operator menyelesaikan readiness check.</p>
        <form method="POST" action="{{ route('central.tenants.store') }}">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">
            <label for="school_name">Nama sekolah</label>
            <input id="school_name" name="school_name" value="{{ old('school_name') }}" required maxlength="200">
            <label for="slug">Slug tenant</label>
            <input id="slug" name="slug" value="{{ old('slug') }}" required maxlength="63" pattern="[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?">
            <label for="timezone">Timezone</label>
            <input id="timezone" name="timezone" value="{{ old('timezone', 'Asia/Jakarta') }}" required>
            <label for="admin_name">Nama admin sekolah</label>
            <input id="admin_name" name="admin_name" value="{{ old('admin_name') }}" required maxlength="150">
            <label for="admin_email">Email admin sekolah</label>
            <input id="admin_email" name="admin_email" type="email" value="{{ old('admin_email') }}" required maxlength="254">
            <label for="admin_username">Username lokal admin</label>
            <input id="admin_username" name="admin_username" value="{{ old('admin_username') }}" required maxlength="64">
            <div class="actions"><button type="submit">Buat tenant pending</button></div>
        </form>
    </section>
    @endif

    <section class="card">
        <h2>Sekolah terdaftar</h2>
        <form method="GET" action="{{ route('central.tenants.index') }}">
            <label for="q">Cari nama atau slug sekolah</label>
            <input id="q" name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">Semua status</option>
                @foreach (['pending', 'active', 'suspended', 'archived'] as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $status }}</option>
                @endforeach
            </select>
            <div class="actions"><button type="submit">Cari</button></div>
        </form>
        <p class="muted">{{ $tenants->total() }} tenant ditemukan.</p>
        @if ($tenants->isEmpty())
            <p class="muted">Belum ada tenant sintetis.</p>
        @else
            <table>
                <thead><tr><th>Sekolah</th><th>Host</th><th>Status</th><th>Undangan awal</th><th>Operasi terakhir</th><th>Dibuat</th><th></th></tr></thead>
                <tbody>
                @foreach ($tenants as $tenant)
                    <tr>
                        <td><strong>{{ $tenant['name'] }}</strong><br><code>{{ $tenant['slug'] }}</code></td>
                        <td><code>{{ $tenant['hostname'] ?? '—' }}</code></td>
                        <td><span class="status {{ $tenant['status'] }}">{{ $tenant['status'] }}</span></td>
                        <td>{{ $tenant['bootstrap_status'] ?? 'belum ada' }}</td>
                        <td>{{ $tenant['last_operation_at'] ?? '—' }}</td>
                        <td>{{ $tenant['created_at'] }}</td>
                        <td><a class="button secondary" href="{{ route('central.tenants.show', $tenant['public_ulid']) }}">Detail</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            {{ $tenants->links() }}
        @endif
    </section>
</div>
@endsection
