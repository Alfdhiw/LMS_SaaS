@extends('platform.layout')

@section('title', 'Detail tenant')

@section('content')
@php
    $provisioning = session('provisioning');
    $activationUrl = isset($provisioning['activation_path']) && isset($tenant['hostname'])
        ? rtrim((string) config('saas.invitation_delivery.url_scheme', 'http'), ':/').'://'.$tenant['hostname'].$provisioning['activation_path']
        : null;
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

    <div class="actions">
        @if ($tenant['status'] === 'pending')
            @if (! empty($tenant['bootstrap_invitation_public_ulid']))
                <form method="POST" action="{{ route('central.tenants.invitation.resend', $tenant['public_ulid']) }}">
                    @csrf
                    <input type="hidden" name="invitation_public_ulid" value="{{ $tenant['bootstrap_invitation_public_ulid'] }}">
                    <button class="secondary" type="submit">Kirim ulang invitation</button>
                </form>
            @endif
            <form method="POST" action="{{ route('central.tenants.activate', $tenant['public_ulid']) }}">
                @csrf
                <input type="hidden" name="expected_status" value="pending">
                <input type="hidden" name="reason" value="readiness_check">
                <button type="submit">Aktifkan tenant</button>
            </form>
        @elseif ($tenant['status'] === 'active')
            <form method="POST" action="{{ route('central.tenants.suspend', $tenant['public_ulid']) }}">
                @csrf
                <input type="hidden" name="expected_status" value="active">
                <input type="hidden" name="reason" value="operator_request">
                <button class="danger" type="submit">Suspend tenant</button>
            </form>
            <form method="POST" action="{{ route('central.tenants.archive', $tenant['public_ulid']) }}">
                @csrf
                <input type="hidden" name="expected_status" value="active">
                <input type="hidden" name="reason" value="offboarding_requested">
                <button class="danger" type="submit">Archive tenant</button>
            </form>
        @elseif ($tenant['status'] === 'suspended')
            <form method="POST" action="{{ route('central.tenants.restore', $tenant['public_ulid']) }}">
                @csrf
                <input type="hidden" name="expected_status" value="suspended">
                <input type="hidden" name="reason" value="operator_request">
                <button type="submit">Restore tenant</button>
            </form>
        @endif
    </div>
</section>
@endsection
