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
                @php
                    $currentUser = auth()->user();
                @endphp

                @if($currentUser && $currentUser->isFullAdmin())
                    {{-- Super Admin & Systems Engineer: Full Access to Every Module and Dashboard --}}
                    <div class="nav-section-divider">
                        <span>DASHBOARDS</span>
                    </div>
                    <a href="/" class="nav-item {{ request()->is('/') ? 'active' : '' }}">
                        <i class="fa-solid fa-table-cells-large"></i>
                        <span>Main Overview</span>
                    </a>
                    <a href="{{ route('dashboard.executive') }}" class="nav-item {{ request()->routeIs('dashboard.executive') ? 'active' : '' }}">
                        <i class="fa-solid fa-chart-line"></i>
                        <span>Executive Oversight</span>
                    </a>
                    <a href="{{ route('dashboard.hr') }}" class="nav-item {{ request()->routeIs('dashboard.hr') ? 'active' : '' }}">
                        <i class="fa-solid fa-users-viewfinder"></i>
                        <span>HR Workforce</span>
                    </a>
                    <a href="{{ route('dashboard.audit') }}" class="nav-item {{ request()->routeIs('dashboard.audit') ? 'active' : '' }}">
                        <i class="fa-solid fa-shield-halved"></i>
                        <span>Audit &amp; Security</span>
                    </a>

                    <div class="nav-section-divider">
                        <span>HARDWARE &amp; DEVICES</span>
                    </div>
                    <a href="{{ route('devices.index') }}" class="nav-item {{ request()->routeIs('devices.*') && !request()->routeIs('devices.alerts.*') && !request()->routeIs('devices.activity.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-server"></i>
                        <span>Devices</span>
                    </a>
                    <a href="{{ route('devices.alerts.index') }}" class="nav-item {{ request()->routeIs('devices.alerts.*') ? 'active' : '' }}">
                        <i class="fa-regular fa-bell"></i>
                        <span>Device Alerts</span>
                        <span class="nav-pill-badge-red">{{ $openDeviceAlertCount ?? 0 }}</span>
                    </a>
                    <a href="{{ route('devices.activity.index') }}" class="nav-item {{ request()->routeIs('devices.activity.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-wave-square"></i>
                        <span>Device Activity</span>
                    </a>
                    <a href="{{ route('terminals.index') }}" class="nav-item {{ request()->routeIs('terminals.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-fingerprint"></i>
                        <span>Terminals</span>
                    </a>

                    <div class="nav-section-divider">
                        <span>ACCESS CONTROL</span>
                    </div>
                    <a href="{{ route('personnel.index') }}" class="nav-item {{ request()->routeIs('personnel.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-users"></i>
                        <span>Personnel</span>
                    </a>
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

                    <div class="nav-section-divider">
                        <span>RECORDS &amp; AUDIT</span>
                    </div>
                    <a href="{{ route('attendance.index') }}" class="nav-item {{ request()->routeIs('attendance.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-clipboard-user"></i>
                        <span>Attendance</span>
                    </a>
                    <a href="{{ route('events.index') }}" class="nav-item {{ request()->routeIs('events.index') ? 'active' : '' }}">
                        <i class="fa-solid fa-list-check"></i>
                        <span>Access Events</span>
                    </a>
                    <a href="{{ route('access.audit.index') }}" class="nav-item {{ request()->routeIs('access.audit.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                        <span>System Audit Logs</span>
                    </a>

                    <div class="nav-section-divider">
                        <span>ADMINISTRATION</span>
                    </div>
                    <a href="{{ route('admin.users.index') }}" class="nav-item {{ request()->routeIs('admin.users.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-users-gear"></i>
                        <span>User Accounts</span>
                    </a>

                @elseif($currentUser && ($currentUser->isDirector() || $currentUser->isDeputyDirector()))
                    {{-- Director & Deputy Director Navigation --}}
                    <div class="nav-section-divider">
                        <span>EXECUTIVE</span>
                    </div>
                    <a href="{{ route('dashboard.executive') }}" class="nav-item {{ request()->routeIs('dashboard.executive') ? 'active' : '' }}">
                        <i class="fa-solid fa-chart-line"></i>
                        <span>Executive Dashboard</span>
                    </a>
                    <a href="{{ route('attendance.index') }}" class="nav-item {{ request()->routeIs('attendance.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-clipboard-user"></i>
                        <span>Attendance Reports</span>
                    </a>
                    <a href="{{ route('personnel.index') }}" class="nav-item {{ request()->routeIs('personnel.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-users"></i>
                        <span>Personnel Directory</span>
                    </a>
                    <a href="{{ route('events.index') }}" class="nav-item {{ request()->routeIs('events.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-shield-halved"></i>
                        <span>Access Security Events</span>
                    </a>
                    <a href="{{ route('access.audit.index') }}" class="nav-item {{ request()->routeIs('access.audit.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                        <span>Audit Oversight</span>
                    </a>

                @elseif($currentUser && $currentUser->isHrOfficer())
                    {{-- HR Officer Navigation --}}
                    <div class="nav-section-divider">
                        <span>WORKFORCE</span>
                    </div>
                    <a href="{{ route('dashboard.hr') }}" class="nav-item {{ request()->routeIs('dashboard.hr') ? 'active' : '' }}">
                        <i class="fa-solid fa-users-viewfinder"></i>
                        <span>HR Dashboard</span>
                    </a>
                    <a href="{{ route('personnel.index') }}" class="nav-item {{ request()->routeIs('personnel.index') || request()->routeIs('personnel.edit') || request()->routeIs('personnel.show') ? 'active' : '' }}">
                        <i class="fa-solid fa-users"></i>
                        <span>Personnel Directory</span>
                    </a>
                    <a href="{{ route('personnel.create') }}" class="nav-item {{ request()->routeIs('personnel.create') ? 'active' : '' }}">
                        <i class="fa-solid fa-user-plus"></i>
                        <span>Register Personnel</span>
                    </a>
                    <a href="{{ route('attendance.index') }}" class="nav-item {{ request()->routeIs('attendance.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-clipboard-user"></i>
                        <span>Attendance Records</span>
                    </a>
                    <a href="{{ route('access.sync.index') }}" class="nav-item {{ request()->routeIs('access.sync.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-arrows-rotate"></i>
                        <span>Personnel Sync Status</span>
                    </a>

                @elseif($currentUser && $currentUser->isAuditOfficer())
                    {{-- Audit Officer Navigation --}}
                    <div class="nav-section-divider">
                        <span>AUDIT &amp; COMPLIANCE</span>
                    </div>
                    <a href="{{ route('dashboard.audit') }}" class="nav-item {{ request()->routeIs('dashboard.audit') ? 'active' : '' }}">
                        <i class="fa-solid fa-shield-halved"></i>
                        <span>Audit Dashboard</span>
                    </a>
                    <a href="{{ route('access.audit.index') }}" class="nav-item {{ request()->routeIs('access.audit.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                        <span>System Change Logs</span>
                    </a>
                    <a href="{{ route('events.index') }}" class="nav-item {{ request()->routeIs('events.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-list-check"></i>
                        <span>Access Events</span>
                    </a>
                    <a href="{{ route('attendance.index') }}" class="nav-item {{ request()->routeIs('attendance.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-clipboard-user"></i>
                        <span>Attendance Records</span>
                    </a>
                    <a href="{{ route('personnel.index') }}" class="nav-item {{ request()->routeIs('personnel.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-users"></i>
                        <span>Personnel Directory</span>
                    </a>
                    <a href="{{ route('access.sync.index') }}" class="nav-item {{ request()->routeIs('access.sync.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-arrows-rotate"></i>
                        <span>Device Sync Logs</span>
                    </a>

                @else
                    {{-- General Viewer Navigation --}}
                    <a href="/" class="nav-item {{ request()->is('/') ? 'active' : '' }}">
                        <i class="fa-solid fa-table-cells-large"></i>
                        <span>Dashboard</span>
                    </a>
                    <a href="{{ route('personnel.index') }}" class="nav-item {{ request()->routeIs('personnel.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-users"></i>
                        <span>Personnel Directory</span>
                    </a>
                    <a href="{{ route('attendance.index') }}" class="nav-item {{ request()->routeIs('attendance.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-clipboard-user"></i>
                        <span>Attendance</span>
                    </a>
                @endif
            </nav>

            {{-- User profile block at bottom of sidebar --}}
            <div class="sidebar-user-block">
                <div class="sidebar-user-avatar">{{ strtoupper(substr(auth()->user()->name ?? 'A', 0, 1)) }}</div>
                <div class="sidebar-user-info">
                    <span class="sidebar-user-name">{{ auth()->user()->name ?? 'Admin' }}</span>
                    <span class="sidebar-user-role">{{ auth()->user()?->roleLabel() ?? 'Administrator' }}</span>
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
                    <form method="GET" action="{{ route('events.index') }}" class="header-search-form" id="header-global-search-form" autocomplete="off">
                        <div class="header-search-bar">
                            <i class="fa-solid fa-magnifying-glass search-icon"></i>
                            <input type="text" name="search" id="header-search-input" placeholder="Search personnel, device, or event..." aria-label="Search" class="search-input" value="{{ request('search') }}">
                            <span class="search-spinner" id="header-search-spinner" style="display:none;"><i class="fa-solid fa-spinner fa-spin"></i></span>
                        </div>
                        <div class="search-dropdown-menu" id="header-search-dropdown"></div>
                    </form>
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
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('header-search-input');
        const dropdown = document.getElementById('header-search-dropdown');
        const spinner = document.getElementById('header-search-spinner');
        if (!searchInput || !dropdown) return;

        let debounceTimer = null;

        searchInput.addEventListener('input', function() {
            const query = this.value.trim();
            clearTimeout(debounceTimer);

            if (query.length < 2) {
                dropdown.style.display = 'none';
                dropdown.innerHTML = '';
                if (spinner) spinner.style.display = 'none';
                return;
            }

            if (spinner) spinner.style.display = 'inline-block';

            debounceTimer = setTimeout(() => {
                fetch('{{ route("search.live") }}?q=' + encodeURIComponent(query), {
                    headers: { 'Accept': 'application/json' }
                })
                .then(res => res.json())
                .then(data => {
                    if (spinner) spinner.style.display = 'none';
                    renderSearchResults(data, query);
                })
                .catch(() => {
                    if (spinner) spinner.style.display = 'none';
                });
            }, 250);
        });

        function renderSearchResults(data, query) {
            if (!data || data.total === 0) {
                dropdown.innerHTML = '<div class="search-empty">No results found for "<strong>' + escapeHtml(query) + '</strong>"</div>';
                dropdown.style.display = 'block';
                return;
            }

            let html = '';

            if (data.personnel && data.personnel.length > 0) {
                html += '<div class="search-category-title"><i class="fa-solid fa-users"></i> Personnel (' + data.personnel.length + ')</div>';
                data.personnel.forEach(item => {
                    html += `
                        <a href="${item.url}" class="search-item">
                            <div class="search-item-icon personnel-icon"><i class="${item.icon}"></i></div>
                            <div class="search-item-info">
                                <div class="search-item-title">${escapeHtml(item.title)}</div>
                                <div class="search-item-sub">${escapeHtml(item.subtitle)}</div>
                            </div>
                            <span class="search-badge">${escapeHtml(item.badge)}</span>
                        </a>
                    `;
                });
            }

            if (data.devices && data.devices.length > 0) {
                html += '<div class="search-category-title"><i class="fa-solid fa-server"></i> Devices (' + data.devices.length + ')</div>';
                data.devices.forEach(item => {
                    html += `
                        <a href="${item.url}" class="search-item">
                            <div class="search-item-icon device-icon"><i class="${item.icon}"></i></div>
                            <div class="search-item-info">
                                <div class="search-item-title">${escapeHtml(item.title)}</div>
                                <div class="search-item-sub">${escapeHtml(item.subtitle)}</div>
                            </div>
                            <span class="search-badge ${item.badge.toLowerCase() === 'online' ? 'badge-online' : ''}">${escapeHtml(item.badge)}</span>
                        </a>
                    `;
                });
            }

            if (data.events && data.events.length > 0) {
                html += '<div class="search-category-title"><i class="fa-solid fa-bolt"></i> Access Events (' + data.events.length + ')</div>';
                data.events.forEach(item => {
                    html += `
                        <a href="${item.url}" class="search-item">
                            <div class="search-item-icon event-icon"><i class="${item.icon}"></i></div>
                            <div class="search-item-info">
                                <div class="search-item-title">${escapeHtml(item.title)}</div>
                                <div class="search-item-sub">${escapeHtml(item.subtitle)}</div>
                            </div>
                            <span class="search-badge ${item.badge === 'Granted' ? 'badge-online' : 'badge-denied'}">${escapeHtml(item.badge)}</span>
                        </a>
                    `;
                });
            }

            html += `
                <div class="search-footer">
                    <a href="{{ route('events.index') }}?search=${encodeURIComponent(query)}">
                        View all matching events in Audit Log <i class="fa-solid fa-arrow-right"></i>
                    </a>
                </div>
            `;

            dropdown.innerHTML = html;
            dropdown.style.display = 'block';
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/[&<>"']/g, function(m) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
            });
        }

        document.addEventListener('click', function(e) {
            if (!e.target.closest('#header-global-search-form')) {
                dropdown.style.display = 'none';
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                dropdown.style.display = 'none';
            }
        });

        searchInput.addEventListener('focus', function() {
            if (this.value.trim().length >= 2 && dropdown.innerHTML.trim() !== '') {
                dropdown.style.display = 'block';
            }
        });
    });
    </script>
    @stack('scripts')
</body>
</html>
