<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') — SmartFill</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/smartfill.css') }}?v={{ file_exists(public_path('assets/smartfill.css')) ? filemtime(public_path('assets/smartfill.css')) : time() }}">
    @stack('styles')
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="{{ route('dashboard') }}">
            <span class="brand-mark"><span></span><span></span><span></span></span>
            <span><strong>smartfill</strong><small>IoT MONITORING</small></span>
        </a>
        <div class="side-label">MENU UTAMA</div>
        <nav class="nav-menu">
            <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard*') ? 'active' : '' }}"><span class="nav-icon">▦</span>Dashboard</a>
            <a href="{{ route('history') }}" class="{{ request()->routeIs('history') ? 'active' : '' }}"><span class="nav-icon">◷</span>Riwayat Refill</a>
            <a href="{{ route('athletes') }}" class="{{ request()->routeIs('athletes') ? 'active' : '' }}"><span class="nav-icon">♙</span>Data Atlet & Botol</a>
            <a href="{{ route('settings') }}" class="{{ request()->routeIs('settings') ? 'active' : '' }}"><span class="nav-icon">⚙</span>Pengaturan</a>
        </nav>
        <div class="sidebar-bottom">
            <div class="side-device"><span class="pulse-dot"></span><div><b>Rack 01</b><small>Single bottle slot</small></div><span class="tiny-chevron">⌄</span></div>
            <div class="side-foot">SMART WATER BOTTLE<br>REFILL REMINDER <span>v1.0</span></div>
        </div>
    </aside>

    <main class="main-area">
        <header class="topbar">
            <button class="mobile-menu" id="mobileMenu" aria-label="Buka menu">☰</button>
            <div class="breadcrumb">SmartFill <span>/</span> <b>@yield('crumb', 'Dashboard')</b></div>
            <div class="top-actions">
                <div class="live-pill"><span class="pulse-dot"></span> LIVE MONITORING</div>
                <div class="top-avatar">OP</div>
            </div>
        </header>
        <section class="page-content">
            @if(session('success'))<div class="flash success">{{ session('success') }}<button onclick="this.parentElement.remove()">×</button></div>@endif
            @if(session('error'))<div class="flash error">{{ session('error') }}<button onclick="this.parentElement.remove()">×</button></div>@endif
            @if($errors->any())<div class="flash error">{{ $errors->first() }}<button onclick="this.parentElement.remove()">×</button></div>@endif
            @yield('content')
        </section>
        <footer class="footer"><span>© {{ date('Y') }} SmartFill Monitoring System</span><span>Built for safer hydration routines</span></footer>
    </main>
</div>
@stack('scripts')
<script src="{{ asset('assets/smartfill.js') }}?v={{ file_exists(public_path('assets/smartfill.js')) ? filemtime(public_path('assets/smartfill.js')) : time() }}"></script>
</body>
</html>
