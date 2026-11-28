@extends('academic.layout')
@section('title', 'Mata pelajaran')
@section('content')
<section><h2>Tambah mata pelajaran</h2>
    <form method="POST" action="/academic/subjects">@csrf
        <label>Kode <input name="code" maxlength="32" required value="{{ old('code') }}"></label>
        <label>Nama <input name="name" maxlength="150" required value="{{ old('name') }}"></label><button>Tambah</button>
    </form>
</section>
@forelse ($subjects as $subject)
    <article><form method="POST" action="/academic/subjects/{{ $subject->public_ulid }}">@csrf
        <label>Kode <input name="code" maxlength="32" required value="{{ $subject->code }}"></label>
        <label>Nama <input name="name" maxlength="150" required value="{{ $subject->name }}"></label>
        <label>Status <select name="is_active"><option value="1" @selected($subject->is_active)>Aktif</option><option value="0" @selected(! $subject->is_active)>Tidak aktif</option></select></label><button>Simpan</button>
    </form></article>
@empty <p>Belum ada mata pelajaran.</p> @endforelse
{{ $subjects->links() }}
@endsection
