@extends('platform.layout')

@section('title', 'Detail tenant')

@section('content')
@php
    $provisioning = session('provisioning');
    $activationUrl = isset($provisioning['activation_path']) && isset($tenant['hostname'])
        ? rtrim((string) config('saas.invitation_delivery.url_scheme', 'http'), ':/').'://'.$tenant['hostname'].$provisioning['activation_path']
        : null;
    $centralUser = request()->attributes->get('central_user');
    $permissions = app(\App\Modules\Platform\Services\PlatformPermissionChecker::class);
    $canTransition = $permissions->allows($centralUser, 'platform.tenants.transition');
    $canUpdate = $permissions->allows($centralUser, 'platform.tenants.update');
@endphp

<p><a href="{{ route('central.tenants.index') }}">← Kembali ke directory</a></p>
<section class="card">
    <h1>{{ $tenant['name'] }}</h1>
    <p><span class="status {{ $tenant['status'] }}">{{ $tenant['status'] }}</span></p>
    <dl>
        <dt>Public ULID</dt><dd><code>{{ $tenant['public_ulid'] }}</code></dd>
        <dt>Slug</dt><dd><code>{{ $tenant['slug'] }}</code></dd>
        <dt>Hostname</dt><dd><code>{{ $tenant['hostname'] ?? '—' }}</code></dd>
        <dt>Timezone</dt><dd>{{ $tenant['timezone'] }}</dd>
        <dt>Status undangan awal</dt><dd>{{ $tenant['bootstrap_status'] ?? 'belum ada' }}</dd>
        <dt>Operasi platform terakhir</dt><dd>{{ $tenant['last_operation_at'] ?? '—' }}</dd>
        <dt>Dibuat</dt><dd>{{ $tenant['created_at'] }}</dd>
    </dl>

    @if ($provisioning && ($provisioning['tenant_public_ulid'] ?? null) === $tenant['public_ulid'])
        <div class="notice">
            <strong>Hasil invitation:</strong> {{ $provisioning['invitation_delivery'] ?? 'not_sent' }}.
            @if ($activationUrl)
                <br>Link aktivasi lokal: <a href="{{ $activationUrl }}">{{ $activationUrl }}</a>
            @endif
        </div>
    @endif

    @if ($canUpdate && $tenant['status'] !== 'archived')
        <section>
            <h2>Edit metadata sekolah</h2>
            <p class="muted">Slug dan hostname tidak dapat diubah melalui formulir ini.</p>
            <form method="POST" action="{{ route('central.tenants.update', $tenant['public_ulid']) }}">
                @csrf
                @method('PATCH')
                <label>Nama sekolah <input name="name" value="{{ $tenant['name'] }}" maxlength="200" required></label>
                <label>Timezone <input name="timezone" value="{{ $tenant['timezone'] }}" maxlength="64" required></label>
                <div class="actions"><button type="submit">Simpan metadata</button></div>
            </form>
        </section>
    @endif

    <div class="actions">
        @if (app(\App\Modules\Platform\Services\PlatformPermissionChecker::class)->allows(request()->attributes->get('central_user'), 'platform.audit.view'))
            <a class="button secondary" href="{{ route('central.audit.index', ['tenant' => $tenant['public_ulid']]) }}">Audit tenant</a>
        @endif
        @if ($tenant['status'] === 'pending')
            @if ($canUpdate && ! empty($tenant['bootstrap_invitation_public_ulid']))
                <form method="POST" action="{{ route('central.tenants.invitation.resend', $tenant['public_ulid']) }}">
                    @csrf
                    <input type="hidden" name="invitation_public_ulid" value="{{ $tenant['bootstrap_invitation_public_ulid'] }}">
                    <button class="secondary" type="submit">Kirim ulang invitation</button>
                </form>
            @endif
            @if ($canTransition)
            <form method="POST" action="{{ route('central.tenants.activate', $tenant['public_ulid']) }}">
                @csrf
                <input type="hidden" name="expected_status" value="pending">
                <label>Alasan aktivasi <input name="reason" value="readiness_check" pattern="[a-z0-9_:-]+" maxlength="80" required></label>
                <label><input type="checkbox" required> Saya telah memeriksa kesiapan tenant.</label>
                <button type="submit">Aktifkan tenant</button>
            </form>
            @endif
        @elseif ($canTransition && $tenant['status'] === 'active')
            <form method="POST" action="{{ route('central.tenants.suspend', $tenant['public_ulid']) }}">
                @csrf
                <input type="hidden" name="expected_status" value="active">
                <label>Alasan suspend <input name="reason" value="operator_request" pattern="[a-z0-9_:-]+" maxlength="80" required></label>
                <label><input type="checkbox" required> Saya mengonfirmasi penangguhan.</label>
                <button class="danger" type="submit">Suspend tenant</button>
            </form>
            <form method="POST" action="{{ route('central.tenants.archive', $tenant['public_ulid']) }}">
                @csrf
                <input type="hidden" name="expected_status" value="active">
                <label>Alasan archive <input name="reason" value="offboarding_requested" pattern="[a-z0-9_:-]+" maxlength="80" required></label>
                <label><input type="checkbox" required> Saya mengonfirmasi permintaan archive dan kesiapan offboarding.</label>
                <button class="danger" type="submit">Archive tenant</button>
            </form>
        @elseif ($canTransition && $tenant['status'] === 'suspended')
            <form method="POST" action="{{ route('central.tenants.restore', $tenant['public_ulid']) }}">
                @csrf
                <input type="hidden" name="expected_status" value="suspended">
                <label>Alasan restore <input name="reason" value="operator_request" pattern="[a-z0-9_:-]+" maxlength="80" required></label>
                <label><input type="checkbox" required> Saya mengonfirmasi pemulihan tenant.</label>
                <button type="submit">Restore tenant</button>
            </form>
        @endif
    </div>
</section>
@endsection
