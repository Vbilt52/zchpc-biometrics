<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'ZCHPC Access Control Suite')</title>
    <!-- Add FontAwesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Main CSS -->
    <link rel="stylesheet" href="/css/app.css?v={{ time() }}">
    @stack('styles')
</head>
<body>
    <div class="app-container">
        <!-- Sidebar Navigation -->
        <aside class="sidebar">
            <div class="sidebar-building-header">
                <img src="{{ asset('images/zchpc-building.png') }}" alt="ZCHPC Logo" class="sidebar-building-img">
            </div>
            
            <div class="sidebar-brand-block">
                <p class="sidebar-brand-subtitle">Access Control Suite</p>
            </div>
            
            <nav class="sidebar-nav">
                <a href="/" class="nav-item {{ request()->is('/') ? 'active' : '' }}">
                    <i class="fa-solid fa-table-cells-large"></i>
                    <span>Dashboard</span>
                </a>
                <a href="{{ route('devices.index') }}" class="nav-item {{ request()->routeIs('devices.*') && !request()->routeIs('devices.alerts.*') && !request()->routeIs('devices.activity.*') ? 'active' : '' }}">
                    <i class="fa-solid fa-server"></i>
                    <span>Devices</span>
                </a>
                <a href="{{ route('devices.alerts.index') }}" class="nav-item {{ request()->routeIs('devices.alerts.*') ? 'active' : '' }}">
                    <i class="fa-regular fa-bell"></i>
                    <span>Device Alerts</span>
                    <span class="nav-pill-badge-red">{{ $openDeviceAlertCount ?? 2 }}</span>
                </a>
                <a href="{{ route('devices.activity.index') }}" class="nav-item {{ request()->routeIs('devices.activity.*') ? 'active' : '' }}">
                    <i class="fa-solid fa-chart-line"></i>
                    <span>Device Activity</span>
                    <i class="fa-solid fa-chevron-right nav-chevron"></i>
                </a>
                <a href="{{ route('terminals.index') }}" class="nav-item {{ request()->routeIs('terminals.*') ? 'active' : '' }}">
                    <i class="fa-solid fa-fingerprint"></i>
                    <span>Terminals</span>
                </a>
                <a href="{{ route('personnel.index') }}" class="nav-item {{ request()->routeIs('personnel.*') ? 'active' : '' }}">
                    <i class="fa-solid fa-users"></i>
                    <span>Personnel</span>
                </a>

                <div class="nav-section-divider">
                    <span>ACCESS CONTROL</span>
                </div>

                <a href="{{ route('access.index') }}" class="nav-item {{ request()->is('access') || (request()->is('access/users*') && ! request()->is('access/users/create*')) ? 'active' : '' }}">
                    <i class="fa-solid fa-user-shield"></i>
                    <span>Users &amp; Access</span>
                </a>
                <a href="{{ route('access.users.create') }}" class="nav-item {{ request()->is('access/users/create*') ? 'active' : '' }}">
                    <i class="fa-solid fa-user-plus"></i>
                    <span>Add New User</span>
                </a>
                <a href="{{ route('access.doors.index') }}" class="nav-item {{ request()->is('access/doors*') ? 'active' : '' }}">
                    <i class="fa-solid fa-door-closed"></i>
                    <span>Doors &amp; Controllers</span>
                </a>
                <a href="{{ route('access.matrix.index') }}" class="nav-item {{ request()->is('access/matrix*') ? 'active' : '' }}">
                    <i class="fa-solid fa-table-cells"></i>
                    <span>Access Matrix</span>
                </a>
                <a href="{{ route('access.sync.index') }}" class="nav-item {{ request()->is('access/sync*') ? 'active' : '' }}">
                    <i class="fa-solid fa-arrows-rotate"></i>
                    <span>Device Sync</span>
                </a>
            </nav>

            {{-- Admin profile + sign-out at bottom of sidebar --}}
            <div class="sidebar-user-block">
                <div class="sidebar-user-avatar">{{ strtoupper(substr(auth()->user()->name ?? 'A', 0, 1)) }}</div>
                <div class="sidebar-user-info">
                    <span class="sidebar-user-name">{{ auth()->user()->name ?? 'Admin' }}</span>
                    <span class="sidebar-user-role">Administrator</span>
                </div>
                <form action="{{ route('logout') }}" method="POST" style="margin:0;">
                    @csrf
                    <button type="submit" class="sidebar-signout-btn" title="Sign out">
                        <i class="fa-solid fa-right-from-bracket"></i>
                    </button>
                </form>
            </div>
        </aside>

        <!-- Main Content Area -->
        <main class="main-content">
            <header class="top-header">
                <div class="top-header-left">
                    <button class="sidebar-toggle-btn" type="button" aria-label="Toggle navigation">
                        <i class="fa-solid fa-bars"></i>
                    </button>
                </div>

                <div class="top-header-center">
                    <div class="header-search-bar">
                        <i class="fa-solid fa-magnifying-glass search-icon"></i>
                        <input type="text" placeholder="Search personnel, device, or event..." aria-label="Search" class="search-input">
                    </div>
                </div>
            </header>

            <div class="content-wrapper">
                @hasSection('header')
                    @if(!request()->is('/'))
                        <div class="subpage-header">
                            <h1 class="subpage-title">@yield('header')</h1>
                        </div>
                    @endif
                @endif
                @yield('content')
            </div>
        </main>
    </div>
    @stack('scripts')
</body>
</html>
