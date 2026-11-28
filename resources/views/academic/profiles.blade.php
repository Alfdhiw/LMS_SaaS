@extends('academic.layout')
@section('title', $type === 'student' ? 'Profil siswa' : 'Profil guru')
@section('content')
<section><h2>Tambah profil</h2><p>Anggota harus sudah diundang dan memiliki role yang sesuai. Profil baru tidak aktif.</p>
    <form method="POST" action="/academic/profiles/{{ $type }}">@csrf
        <label>Username sekolah <input name="username" required maxlength="64" value="{{ old('username') }}"></label>
        <label>{{ $type === 'student' ? 'NIS' : 'Kode pegawai' }} (opsional) <input name="code" maxlength="64" value="{{ old('code') }}"></label><button>Tambah profil</button>
    </form>
</section>
@forelse ($profiles as $profile)
    @php($member = $members[$profile->membership_id] ?? null)
    @php($fact = $facts[$profile->membership_id] ?? null)
    <article><h2>{{ $member['name'] ?? 'Anggota tidak tersedia' }}</h2>
        <p>Username: {{ $member['username'] ?? '-' }} · Akun: {{ $fact?->userStatus ?? '-' }} · Membership: {{ $fact?->membershipStatus ?? '-' }} · Role: {{ $fact ? implode(', ', $fact->roleKeys) : '-' }}</p>
        <p>Kelayakan profil: {{ $profile->status === 'active' && $fact?->isActive() && $fact?->hasRole($type) ? 'Aktif dan memenuhi syarat' : 'Tidak aktif atau belum memenuhi syarat' }}</p>
        <form method="POST" action="/academic/profiles/{{ $type }}/{{ $profile->public_ulid }}">@csrf
            <label>{{ $type === 'student' ? 'NIS' : 'Kode pegawai' }} <input name="code" maxlength="64" value="{{ $type === 'student' ? $profile->nis : $profile->employee_code }}"></label>
            <label>Status profil <select name="status"><option value="inactive" @selected($profile->status === 'inactive')>Tidak aktif</option><option value="active" @selected($profile->status === 'active')>Aktif</option></select></label><button>Simpan</button>
        </form>
    </article>
@empty <p>Belum ada profil.</p> @endforelse
{{ $profiles->links() }}
@endsection
