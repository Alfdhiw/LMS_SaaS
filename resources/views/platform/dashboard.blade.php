@extends('platform.layout')

@section('title', 'Dashboard platform')

@section('content')
<h1>Dashboard platform</h1>
<p class="muted">Ringkasan operasional tenant; tidak menampilkan data siswa atau akademik.</p>
@if (! empty($recoveryCodes))
    <section class="card" aria-label="Recovery code">
        <h2>Simpan recovery code</h2>
        <p>Setiap kode hanya dapat digunakan satu kali. Kode ini tidak akan ditampilkan lagi.</p>
        <ul>@foreach ($recoveryCodes as $recoveryCode)<li><code>{{ $recoveryCode }}</code></li>@endforeach</ul>
    </section>
@endif
<section class="card">
    <h2>Ringkasan sekolah</h2>
    <dl>
        <dt>Total tenant</dt><dd>{{ $summary['total'] }}</dd>
        <dt>Pending</dt><dd>{{ $summary['statuses']['pending'] }}</dd>
        <dt>Aktif</dt><dd>{{ $summary['statuses']['active'] }}</dd>
        <dt>Suspended</dt><dd>{{ $summary['statuses']['suspended'] }}</dd>
        <dt>Archived</dt><dd>{{ $summary['statuses']['archived'] }}</dd>
        <dt>Baru 30 hari terakhir</dt><dd>{{ $summary['new_last_30_days'] }}</dd>
        <dt>Tenant pending dengan undangan admin belum diterima</dt><dd>{{ $summary['pending_invitations'] }}</dd>
    </dl>
    <div class="actions"><a class="button" href="{{ route('central.tenants.index') }}">Lihat sekolah / tambah tenant</a></div>
</section>
<section class="card">
    <h2>Kegagalan provisioning terakhir</h2>
    @if ($summary['last_failure'])
        <p><code>{{ $summary['last_failure']['code'] }}</code> — {{ $summary['last_failure']['created_at'] }}</p>
    @else
        <p class="muted">Tidak ada kegagalan provisioning yang tercatat.</p>
    @endif
</section>
@endsection
