@extends('platform.layout')

@section('title', 'Audit platform')

@section('content')
<h1>Audit platform</h1>
<p class="muted">Hanya event operasional platform. Metadata, token, email, dan data siswa tidak ditampilkan.
Hasil ditentukan dari akhiran nama event; event lainnya tidak diklasifikasikan.</p>
<section class="card">
    <form method="GET" action="{{ route('central.audit.index') }}">
        <label for="tenant">ULID tenant</label><input id="tenant" name="tenant" value="{{ $filters['tenant'] ?? '' }}" maxlength="26">
        <label for="actor">ULID aktor</label><input id="actor" name="actor" value="{{ $filters['actor'] ?? '' }}" maxlength="26">
        <label for="action">Nama event tepat</label><input id="action" name="action" value="{{ $filters['action'] ?? '' }}" maxlength="120">
        <label for="from">Dari tanggal</label><input id="from" name="from" type="date" value="{{ $filters['from'] ?? '' }}">
        <label for="to">Sampai tanggal</label><input id="to" name="to" type="date" value="{{ $filters['to'] ?? '' }}">
        <label for="result">Hasil event</label><select id="result" name="result">
            <option value="">Semua</option>
            <option value="succeeded" @selected(($filters['result'] ?? '') === 'succeeded')>Berhasil</option>
            <option value="failed" @selected(($filters['result'] ?? '') === 'failed')>Gagal</option>
        </select>
        <div class="actions"><button type="submit">Filter</button><a class="button secondary" href="{{ route('central.audit.index') }}">Reset</a></div>
    </form>
</section>
<section class="card">
    <h2>Event ({{ $events->total() }})</h2>
    @if ($events->isEmpty())
        <p class="muted">Tidak ada event yang cocok.</p>
    @else
        <table><thead><tr><th>Waktu</th><th>Event</th><th>Hasil</th><th>Tenant</th><th>ULID aktor</th></tr></thead><tbody>
        @foreach ($events as $event)
            <tr><td>{{ $event['created_at'] }}</td><td><code>{{ $event['action'] }}</code></td>
                <td>{{ $event['result'] }}</td>
                <td>{{ $event['tenant_name'] ?? 'Platform' }} @if ($event['tenant_ulid'])<br><code>{{ $event['tenant_ulid'] }}</code>@endif</td>
                <td><code>{{ $event['actor_ulid'] ?? '—' }}</code></td></tr>
        @endforeach
        </tbody></table>
        {{ $events->links() }}
    @endif
</section>
@endsection
