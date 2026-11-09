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
                <h2 class="sidebar-brand-title">ZCHPC</h2>
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
        </aside>

        <!-- Main Content Area -->
        <main class="main-content">
            <header class="top-header">
                <div class="top-header-left">
                    <button class="sidebar-toggle-btn" type="button" aria-label="Toggle navigation">
                        <i class="fa-solid fa-bars"></i>
                    </button>
                    <div class="header-brand-wrap">
                        <span class="header-brand-name">ZCHPC Access Control Suite</span>
                        <span class="header-brand-divider">|</span>
                        <span class="header-brand-sub">Zimbabwe Centre for High Performance Computing</span>
                    </div>
                </div>

                <div class="top-header-center">
                    <div class="header-search-bar">
                        <i class="fa-solid fa-magnifying-glass search-icon"></i>
                        <input type="text" placeholder="Search personnel, device, or event..." aria-label="Search" class="search-input">
                    </div>
                </div>

                <div class="top-header-right">
                    <a href="{{ route('devices.alerts.index') }}" class="bell-button" title="Device alerts" aria-label="Device alerts">
                        <i class="fa-solid fa-bell"></i>
                        <span class="bell-dot"></span>
                    </a>

                    <div class="user-profile-badge">
                        <div class="user-avatar-circle">
                            {{ strtoupper(substr(auth()->user()->name ?? 'A', 0, 1)) }}
                        </div>
                        <span class="user-display-name">{{ auth()->user()->name ?? 'Admin' }}</span>
                        <i class="fa-solid fa-chevron-down user-caret"></i>
                        
                        <div class="user-dropdown-popover">
                            <form action="{{ route('logout') }}" method="POST" style="margin:0;">
                                @csrf
                                <button type="submit" class="logout-link-btn">
                                    <i class="fa-solid fa-right-from-bracket"></i> Sign out
                                </button>
                            </form>
                        </div>
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
