@extends('smartfill.layout')
@section('title','Riwayat Refill')
@section('crumb','Riwayat Refill')
@section('content')
<div class="page-heading"><div><div class="eyebrow">ACTIVITY / RECORDS</div><h1>Riwayat Refill</h1><p>Catatan siklus pengisian pada Rack 01, termasuk hasil dan durasinya.</p></div><div class="heading-meta"><span class="date-icon">◷</span><div><b>{{ $refills->total() }} catatan</b><small>Seluruh riwayat tersimpan</small></div></div></div>
<section class="panel table-panel full-panel">
    <div class="panel-header"><div><div class="eyebrow">REFILL LOG</div><h2>Semua Aktivitas</h2></div>
    <form method="GET" class="filter-form"><select name="result" onchange="this.form.submit()"><option value="">Semua hasil</option><option value="completed" @selected(request('result')==='completed')>Berhasil</option><option value="failed" @selected(request('result')==='failed')>Gagal</option><option value="cancelled" @selected(request('result')==='cancelled')>Dibatalkan</option><option value="filling" @selected(request('result')==='filling')>Sedang mengisi</option></select></form></div>
    <div class="table-wrap"><table><thead><tr><th>ID REFILL</th><th>TANGGAL & WAKTU</th><th>PENGGUNA</th><th>BOTOL</th><th>BERAT AWAL</th><th>BERAT AKHIR</th><th>HASIL</th><th>DURASI</th></tr></thead><tbody>
    @forelse($refills as $refill)<tr><td><span class="mono">{{ substr($refill->refill_id,0,8) }}</span></td><td><b>{{ $refill->started_at?->timezone('Asia/Jakarta')->format('d M Y') }}</b><small>{{ $refill->started_at?->timezone('Asia/Jakarta')->format('H:i:s') }} WIB</small></td><td>{{ $refill->athlete?->name ?? '—' }}</td><td>{{ $refill->bottle?->code ?? '—' }}</td><td>{{ $refill->start_total_weight_g !== null ? number_format($refill->start_total_weight_g,1).' g' : '—' }}</td><td>{{ $refill->end_total_weight_g !== null ? number_format($refill->end_total_weight_g,1).' g' : '—' }}</td><td><span class="result-pill {{ $refill->status==='completed'?'result-success':(in_array($refill->status,['failed','cancelled'])?'result-failed':'result-pending') }}">{{ strtoupper($refill->status) }}</span></td><td>{{ $refill->started_at && $refill->finished_at ? $refill->started_at->diffInSeconds($refill->finished_at).' dtk' : '—' }}</td></tr>
    @empty<tr><td colspan="8" class="empty-state">Belum ada data refill untuk Rack 01.</td></tr>@endforelse
    </tbody></table></div><div class="pagination-wrap">{{ $refills->links() }}</div>
</section>
@endsection
