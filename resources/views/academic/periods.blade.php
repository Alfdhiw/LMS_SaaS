@extends('academic.layout')
@section('title', 'Tahun ajaran dan semester')
@section('content')
<section><h2>Tambah tahun ajaran</h2>
    <form method="POST" action="/academic/years">@csrf
        <label>Nama <input name="name" required maxlength="40" value="{{ old('name') }}"></label>
        <label>Mulai <input type="date" name="starts_on" required value="{{ old('starts_on') }}"></label>
        <label>Berakhir <input type="date" name="ends_on" required value="{{ old('ends_on') }}"></label>
        <button>Tambah draft</button>
    </form>
</section>
@forelse ($years as $year)
    <article><h2>{{ $year->name }} · {{ $year->status }}</h2>
        <p>{{ $year->starts_on }} sampai {{ $year->ends_on }}</p>
        @if ($year->status === 'draft' && empty($semesters[$year->id]))<details><summary>Ubah tahun draft</summary><form method="POST" action="/academic/years/{{ $year->public_ulid }}">@csrf
            <label>Nama <input name="name" value="{{ $year->name }}" maxlength="40" required></label>
            <label>Mulai <input type="date" name="starts_on" value="{{ $year->starts_on }}" required></label>
            <label>Berakhir <input type="date" name="ends_on" value="{{ $year->ends_on }}" required></label><button>Simpan</button>
        </form></details>@endif
        @if ($year->status === 'draft')<form class="inline" method="POST" action="/academic/years/{{ $year->public_ulid }}/status">@csrf <input type="hidden" name="status" value="active"><button>Aktifkan tahun</button></form>@endif
        @if ($year->status !== 'closed')<form class="inline" method="POST" action="/academic/years/{{ $year->public_ulid }}/status" onsubmit="return confirm('Tutup tahun ajaran? Tahun yang ditutup tidak dapat dibuka kembali.')">@csrf <input type="hidden" name="status" value="closed"><button>Tutup tahun</button></form>@endif
        <h3>Semester</h3>
        @forelse (($semesters[$year->id] ?? []) as $semester)
            <div><strong>{{ $semester->code === 'odd' ? 'Ganjil' : 'Genap' }}</strong> · {{ $semester->starts_on }} – {{ $semester->ends_on }} · {{ $semester->status }}
                @if ($semester->status === 'draft' && $year->status !== 'closed')<details><summary>Ubah tanggal semester</summary><form method="POST" action="/academic/semesters/{{ $semester->public_ulid }}">@csrf
                    <label>Mulai <input type="date" name="starts_on" value="{{ $semester->starts_on }}" required></label><label>Berakhir <input type="date" name="ends_on" value="{{ $semester->ends_on }}" required></label><button>Simpan</button>
                </form></details>@endif
                @if ($semester->status === 'draft')<form class="inline" method="POST" action="/academic/semesters/{{ $semester->public_ulid }}/status">@csrf <input type="hidden" name="status" value="active"><button>Aktifkan</button></form>@endif
                @if ($semester->status !== 'closed')<form class="inline" method="POST" action="/academic/semesters/{{ $semester->public_ulid }}/status" onsubmit="return confirm('Tutup semester? Semester yang ditutup tidak dapat dibuka kembali.')">@csrf <input type="hidden" name="status" value="closed"><button>Tutup</button></form>@endif
            </div>
        @empty <p>Belum ada semester.</p> @endforelse
        @if ($year->status !== 'closed')<form method="POST" action="/academic/years/{{ $year->public_ulid }}/semesters">@csrf
            <label>Semester <select name="code"><option value="odd">Ganjil</option><option value="even">Genap</option></select></label>
            <label>Mulai <input type="date" name="starts_on" required></label>
            <label>Berakhir <input type="date" name="ends_on" required></label><button>Tambah semester</button>
        </form>@endif
    </article>
@empty <p>Belum ada tahun ajaran.</p> @endforelse
{{ $years->links() }}
@endsection
