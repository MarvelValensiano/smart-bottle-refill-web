@extends('smartfill.layout')
@section('title','Data Atlet & Botol')
@section('crumb','Data Atlet & Botol')
@section('content')
<div class="page-heading">
    <div>
        <div class="eyebrow">MASTER DATA / USERS</div>
        <h1>Data Atlet & Botol</h1>
        <p>Kelola identitas atlet dan profil botol yang digunakan bergantian pada satu slot rak.</p>
    </div>
    <button class="btn-primary" type="button" onclick="document.getElementById('athleteForm').classList.toggle('hidden')">＋ Tambah data</button>
</div>

<!-- FORM TAMBAH ATLET -->
<section class="panel form-panel hidden" id="athleteForm">
    <div class="panel-header">
        <div>
            <div class="eyebrow">NEW PROFILE</div>
            <h2>Tambah Atlet & Botol</h2>
        </div>
        <button class="icon-button" type="button" onclick="document.getElementById('athleteForm').classList.add('hidden')">×</button>
    </div>
    <form method="POST" action="{{ route('athletes.store') }}" class="form-grid">
        @csrf
        <label>Kode atlet<input name="code" placeholder="ATL-004" required></label>
        <label>Nama atlet<input name="name" placeholder="Nama lengkap atlet" required></label>
        <label>Kode botol<input name="bottle_code" placeholder="BTL-004" required></label>
        <label>Nama botol<input name="bottle_name" placeholder="Botol 750mL" required></label>
        <label>Berat botol kosong (gram)<input type="number" step="0.1" min="0" name="empty_weight_g" required></label>
        <label>Kapasitas air (mL / gram)<input type="number" step="1" min="1" name="water_capacity_g" required></label>
        <div class="form-actions"><button class="btn-primary" type="submit">Simpan profil</button></div>
    </form>
</section>

<!-- FORM EDIT ATLET -->
<section class="panel form-panel hidden" id="editAthleteForm">
    <div class="panel-header">
        <div>
            <div class="eyebrow">UPDATE PROFILE</div>
            <h2>Edit Atlet & Botol Terdaftar</h2>
        </div>
        <button class="icon-button" type="button" onclick="document.getElementById('editAthleteForm').classList.add('hidden')">×</button>
    </div>
    <form method="POST" id="editFormElement" action="" class="form-grid">
        @csrf
        <input type="hidden" name="bottle_id" id="editBottleId">
        <label>Kode atlet<input name="code" id="editCode" required></label>
        <label>Nama atlet<input name="name" id="editName" required></label>
        <label>Status atlet
            <select name="is_active" id="editActive">
                <option value="1">Aktif</option>
                <option value="0">Nonaktif</option>
            </select>
        </label>
        <label>Nama botol<input name="bottle_name" id="editBottleName"></label>
        <label>Kode botol<input name="bottle_code" id="editBottleCode"></label>
        <label>Berat botol kosong (gram)<input type="number" step="0.1" min="0" name="empty_weight_g" id="editEmptyWeight"></label>
        <label>Kapasitas air (mL / gram)<input type="number" step="1" min="1" name="water_capacity_g" id="editCapacity"></label>
        <div class="form-actions" style="gap:10px;">
            <button type="button" class="btn-primary" style="background:#8ca0ab;" onclick="document.getElementById('editAthleteForm').classList.add('hidden')">Batal</button>
            <button class="btn-primary" type="submit">Simpan perubahan</button>
        </div>
    </form>
</section>

<!-- TABEL DAFTAR ATLET -->
<section class="panel table-panel full-panel">
    <div class="panel-header">
        <div>
            <div class="eyebrow">ATHLETE DIRECTORY</div>
            <h2>Daftar Atlet Terdaftar</h2>
        </div>
        <span class="subtle-count">{{ $athletes->total() }} atlet</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ATLET</th>
                    <th>KODE</th>
                    <th>PROFIL BOTOL</th>
                    <th>KAPASITAS</th>
                    <th>BERAT KOSONG</th>
                    <th>STATUS</th>
                    <th>AKSI</th>
                </tr>
            </thead>
            <tbody>
            @forelse($athletes as $athlete)
                @foreach($athlete->bottles as $bottle)
                <tr>
                    <td>
                        <div class="person-cell">
                            <span class="person-avatar">{{ strtoupper(substr($athlete->name,0,1)) }}</span>
                            <b>{{ $athlete->name }}</b>
                        </div>
                    </td>
                    <td><span class="mono">{{ $athlete->code }}</span></td>
                    <td><b>{{ $bottle->name }}</b><small>{{ $bottle->code }}</small></td>
                    <td>{{ number_format($bottle->water_capacity_g,0) }} mL</td>
                    <td>{{ number_format($bottle->empty_weight_g,1) }} g</td>
                    <td><span class="result-pill {{ $athlete->is_active?'result-success':'result-failed' }}">{{ $athlete->is_active?'AKTIF':'NONAKTIF' }}</span></td>
                    <td>
                        <button type="button" style="border:1px solid #d0dbe2;background:#f7fafc;padding:5px 12px;border-radius:6px;cursor:pointer;font-weight:700;color:#204050;font-size:11px;"
                            onclick="openEditForm({{ json_encode([
                                'action' => route('athletes.update', $athlete->id),
                                'code' => $athlete->code,
                                'name' => $athlete->name,
                                'is_active' => $athlete->is_active ? 1 : 0,
                                'bottle_id' => $bottle->id,
                                'bottle_code' => $bottle->code,
                                'bottle_name' => $bottle->name,
                                'empty_weight_g' => $bottle->empty_weight_g,
                                'water_capacity_g' => $bottle->water_capacity_g,
                            ]) }})">
                            ✏ Edit
                        </button>
                    </td>
                </tr>
                @endforeach
            @empty
                <tr><td colspan="7" class="empty-state">Belum ada data atlet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="pagination-wrap">{{ $athletes->links() }}</div>
</section>

<!-- PENGATURAN SESI SLOT RACK 01 -->
<section class="panel session-panel">
    <div class="panel-header">
        <div>
            <div class="eyebrow">SINGLE SLOT ASSIGNMENT</div>
            <h2>Atur Pengguna pada Rack 01</h2>
        </div>
        <span class="status-chip chip-blue"><i></i>Sesi Aktif: {{ $activeSession?->athlete?->name ?? 'Belum ada' }}</span>
    </div>

    @if($activeRefill)
        <div style="background:#fff3cd;border:1px solid #ffeeba;color:#856404;padding:12px 16px;border-radius:8px;margin-bottom:14px;font-size:12px;">
            <b>⚠️ Peringatan:</b> Status pengisian saat ini sedang <strong>{{ strtoupper($activeRefill->status) }}</strong>. Mengganti atlet sekarang akan otomatis membatalkan siklus pengisian dan mematikan pompa.
        </div>
    @endif

    <form method="POST" action="{{ route('session.switch') }}" class="session-form" id="sessionForm">
        @csrf
        <label>Pilih atlet
            <select name="athlete_id" id="athleteSelect" required>
                <option value="">Pilih atlet</option>
                @foreach($athletes as $athlete)
                    <option value="{{ $athlete->id }}" @selected($activeSession?->athlete_id === $athlete->id)>{{ $athlete->name }}</option>
                @endforeach
            </select>
        </label>
        <label>Pilih botol
            <select name="bottle_id" id="bottleSelect" required>
                <option value="">Pilih botol</option>
                @foreach($athletes as $athlete)
                    @foreach($athlete->bottles as $bottle)
                        <option value="{{ $bottle->id }}" data-athlete="{{ $athlete->id }}" @selected($activeSession?->bottle_id === $bottle->id)>{{ $bottle->code }} — {{ $bottle->name }}</option>
                    @endforeach
                @endforeach
            </select>
        </label>
        <button class="btn-primary" type="submit">Aktifkan sesi</button>
    </form>
    <p class="form-hint">Mengganti sesi akan menutup sesi aktif sebelumnya. Pastikan botol yang dipilih sesuai dengan botol yang diletakkan pada slot.</p>
</section>
@endsection

@push('scripts')
<script>
document.getElementById('athleteSelect')?.addEventListener('change',function(){
    const id=this.value;
    document.querySelectorAll('#bottleSelect option[data-athlete]').forEach(o=>o.hidden=o.dataset.athlete!==id);
    document.getElementById('bottleSelect').value='';
});

const isRefillOngoing = @json($activeRefill !== null);
document.getElementById('sessionForm')?.addEventListener('submit', function (e) {
    if (isRefillOngoing) {
        const ok = confirm("Yakin ingin ganti atlet? Status botol sedang diisi saat ini. Mengganti atlet akan membatalkan pengisian air dan mematikan pompa.");
        if (!ok) {
            e.preventDefault();
            return false;
        }
    }
});

function openEditForm(data) {
    document.getElementById('athleteForm').classList.add('hidden');
    const editForm = document.getElementById('editAthleteForm');
    editForm.classList.remove('hidden');

    document.getElementById('editFormElement').action = data.action;
    document.getElementById('editCode').value = data.code || '';
    document.getElementById('editName').value = data.name || '';
    document.getElementById('editActive').value = data.is_active;

    document.getElementById('editBottleId').value = data.bottle_id || '';
    document.getElementById('editBottleCode').value = data.bottle_code || '';
    document.getElementById('editBottleName').value = data.bottle_name || '';
    document.getElementById('editEmptyWeight').value = data.empty_weight_g || '';
    document.getElementById('editCapacity').value = data.water_capacity_g || '';

    editForm.scrollIntoView({ behavior: 'smooth' });
}
</script>
@endpush
