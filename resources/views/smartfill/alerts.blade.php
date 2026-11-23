@extends('smartfill.layout')
@section('title','Log Gangguan')
@section('crumb','Log Gangguan')
@section('content')
<div class="page-heading"><div><div class="eyebrow">SYSTEM HEALTH / ALERT CENTER</div><h1>Log Gangguan</h1><p>Daftar kejadian perangkat dan status penanganannya. Periksa penyebab fisik sebelum pemulihan.</p></div><div class="heading-meta"><span class="date-icon">⚑</span><div><b>{{ $events->total() }} kejadian</b><small>Rack 01</small></div></div></div>
<section class="panel table-panel full-panel">
    <div class="panel-header"><div><div class="eyebrow">EVENT HISTORY</div><h2>Pusat Peringatan</h2></div><span class="status-chip {{ $events->whereNull('resolved_at')->count() ? 'chip-red' : 'chip-green' }}"><i></i>{{ $events->whereNull('resolved_at')->count() }} belum diselesaikan</span></div>
    @if($events->whereNull('resolved_at')->count() > 0)
    <div class="safety-banner"><span>!</span><div><b>Sistem membutuhkan pemeriksaan operator</b><p>Akui gangguan hanya setelah penyebab diperiksa. Status pompa dan keputusan keselamatan tetap dikendalikan backend IoT.</p></div><form method="POST" action="{{ route('alerts.acknowledge') }}" onsubmit="return confirm('Sudah memeriksa kondisi fisik rak dan memastikan aman?')">@csrf<button class="btn-primary" type="submit">Akui gangguan</button></form></div>
    @endif
    <div class="event-stack">
    @forelse($events as $event)<article class="event-card"><div class="event-symbol {{ $event->severity==='error'?'event-error':($event->severity==='warning'?'event-warning':'event-info') }}">!</div><div class="event-content"><div class="event-title"><b>{{ str_replace('_',' ',ucfirst($event->type)) }}</b><span class="result-pill {{ $event->resolved_at?'result-success':'result-failed' }}">{{ $event->resolved_at?'SELESAI':'PERLU TINDAKAN' }}</span></div><p>{{ $event->message }}</p><div class="event-meta"><span>Rack 01</span><span>{{ $event->occurred_at?->format('d M Y · H:i:s') }}</span><span>Severity: {{ strtoupper($event->severity) }}</span></div>@if($event->context)<details><summary>Detail teknis</summary><pre>{{ json_encode($event->context, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></details>@endif</div></article>
    @empty<div class="empty-alert large"><span>✓</span><b>Tidak ada gangguan tercatat</b><small>Event perangkat akan muncul di halaman ini.</small></div>@endforelse
    </div><div class="pagination-wrap">{{ $events->links() }}</div>
</section>
@endsection
