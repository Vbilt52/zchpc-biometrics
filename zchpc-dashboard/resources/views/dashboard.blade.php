@extends('layouts.app')

@section('title', 'Dashboard - ZCHPC Access Control')

@section('content')
<p class="device-note">Figures include collected records across all terminals in {{ config('attendance.timezone') }}. <a href="{{ route('events.index') }}">Check collection coverage</a> for offline or delayed terminals.</p>
<div class="dashboard-page animate-fade-in">
    <!-- Top System Overview Header -->
    <div class="page-title-row">
        <div class="page-title-left">
            <div class="page-title-icon">
                <i class="fa-solid fa-table-cells-large"></i>
            </div>
            <div>
                <h1 class="page-title">System Overview</h1>
                <p class="page-subtitle">Real-time access control, devices and system status</p>
            </div>
        </div>
        <div class="date-time-pill">
            <i class="fa-regular fa-calendar"></i>
            <span>{{ $formattedDateTime ?? now()->format('D, d M Y | H:i') }}</span>
        </div>
    </div>

    <!-- Row 1: KPI Summary Cards -->
    <div class="kpi-cards-grid">
        <!-- Active Terminals Card -->
        <div class="kpi-card terminal-kpi">
            <div class="kpi-card-inner">
                <div class="kpi-icon-wrapper terminal-icon">
                    <i class="fa-solid fa-server"></i>
                </div>
                <div class="kpi-meta">
                    <span class="kpi-label">ACTIVE TERMINALS</span>
                    <div class="kpi-stat-value">
                        <span class="kpi-bold-num">{{ $onlineTerminals }}</span>
                        <span class="kpi-total-num">/ {{ $totalTerminals }} Total</span>
                    </div>
                    <div class="terminal-progress-trough">
                        <div class="terminal-progress-bar" style="width: {{ $totalTerminals > 0 ? max(20, min(100, ($onlineTerminals / $totalTerminals) * 100)) : 50 }}%;"></div>
                    </div>
                </div>
            </div>
            <div class="kpi-watermark terminal-watermark">
                <i class="fa-solid fa-server"></i>
            </div>
        </div>

        <!-- Registered Personnel Card -->
        <div class="kpi-card">
            <div class="kpi-card-inner">
                <div class="kpi-icon-wrapper personnel-icon">
                    <i class="fa-solid fa-users"></i>
                </div>
                <div class="kpi-meta">
                    <span class="kpi-label">REGISTERED PERSONNEL</span>
                    <div class="kpi-stat-value text-dark">
                        {{ number_format($totalPersons ?? 0) }}
                    </div>
                </div>
            </div>
            <div class="kpi-watermark">
                <i class="fa-solid fa-users"></i>
            </div>
        </div>

        <!-- Access Events Today Card -->
        <div class="kpi-card">
            <div class="kpi-card-inner">
                <div class="kpi-icon-wrapper events-icon">
                    <i class="fa-solid fa-bolt"></i>
                </div>
                <div class="kpi-meta">
                    <span class="kpi-label">ACCESS EVENTS TODAY</span>
                    <div class="kpi-stat-value text-dark">
                        {{ number_format($eventsTodayCount ?? 0) }}
                    </div>
                </div>
            </div>
            <div class="kpi-watermark">
                <i class="fa-solid fa-chart-simple"></i>
            </div>
        </div>

        <!-- Controlled Doors Card -->
        <div class="kpi-card">
            <div class="kpi-card-inner">
                <div class="kpi-icon-wrapper doors-icon">
                    <i class="fa-solid fa-door-open"></i>
                </div>
                <div class="kpi-meta">
                    <span class="kpi-label">CONTROLLED DOORS</span>
                    <div class="kpi-stat-value text-dark">
                        {{ number_format($controlledDoors ?? 0) }}
                    </div>
                </div>
            </div>
            <div class="kpi-watermark">
                <i class="fa-solid fa-door-open"></i>
            </div>
        </div>
    </div>

    <!-- Row 2: Middle Analytics, Status & Quick Actions -->
    <div class="dashboard-middle-row">
        <!-- Col 1: 7-Day Access Events Chart -->
        <div class="dash-card chart-card">
            <div class="dash-card-header">
                <div class="dash-card-title">
                    <i class="fa-solid fa-chart-line card-title-icon"></i>
                    <h2>Collected Access Events (Last 7 Days)</h2>
                </div>
            </div>
            <div class="chart-container">
                <div class="chart-y-axis">
                    <span>{{ (int) ($chartMax * 100 / 100) }}</span>
                    <span>{{ (int) ($chartMax * 80 / 100) }}</span>
                    <span>{{ (int) ($chartMax * 60 / 100) }}</span>
                    <span>{{ (int) ($chartMax * 40 / 100) }}</span>
                    <span>{{ (int) ($chartMax * 20 / 100) }}</span>
                    <span>0</span>
                </div>
                <div class="chart-main-body">
                    <div class="chart-plot-area">
                        <div class="chart-grid-line" style="bottom: 100%;"></div>
                        <div class="chart-grid-line" style="bottom: 80%;"></div>
                        <div class="chart-grid-line" style="bottom: 60%;"></div>
                        <div class="chart-grid-line" style="bottom: 40%;"></div>
                        <div class="chart-grid-line" style="bottom: 20%;"></div>
                        <div class="chart-grid-line" style="bottom: 0%;"></div>

                        <div class="chart-bars-wrapper">
                            @foreach($chartDays as $bar)
                                <div class="chart-bar-slot">
                                    <div class="chart-bar-fill" style="height: {{ $bar['height'] }}%;" title="{{ $bar['day'] }}: {{ $bar['count'] }} events"></div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <div class="chart-x-labels-row">
                        @foreach($chartDays as $bar)
                            <div class="chart-x-label-item">{{ $bar['day'] }}</div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <!-- Col 2: Device Status -->
        <div class="dash-card device-status-card">
            <div class="dash-card-header">
                <div class="dash-card-title">
                    <i class="fa-solid fa-layer-group card-title-icon"></i>
                    <h2>Device Status</h2>
                </div>
            </div>
            <div class="device-status-list">
                <div class="status-item">
                    <div class="status-item-left">
                        <i class="fa-solid fa-circle-check status-icon-online"></i>
                        <span class="status-label">Online</span>
                    </div>
                    <span class="status-count">{{ $onlineTerminals }}</span>
                </div>
                <div class="status-item">
                    <div class="status-item-left">
                        <i class="fa-regular fa-clock status-icon-offline"></i>
                        <span class="status-label">Offline</span>
                    </div>
                    <span class="status-count">{{ $offlineTerminals }}</span>
                </div>
                <div class="status-item">
                    <div class="status-item-left">
                        <i class="fa-solid fa-server status-icon-total"></i>
                        <span class="status-label">Total Devices</span>
                    </div>
                    <span class="status-count">{{ $totalTerminals }}</span>
                </div>
            </div>
        </div>

        <!-- Col 3: Quick Actions -->
        <div class="dash-card quick-actions-card">
            <div class="dash-card-header">
                <div class="dash-card-title">
                    <i class="fa-solid fa-rocket card-title-icon"></i>
                    <h2>Quick Actions</h2>
                </div>
            </div>
            <div class="quick-actions-grid">
                <a href="{{ route('access.users.create') }}" class="action-btn action-personnel">
                    <i class="fa-solid fa-user-plus"></i>
                    <span>Add Personnel</span>
                </a>
                <a href="{{ route('super-fingerprints.index') }}" class="action-btn action-biometric">
                    <i class="fa-solid fa-fingerprint"></i>
                    <span>Enroll Biometric</span>
                </a>
                <a href="{{ route('access.doors.index') }}" class="action-btn action-doors">
                    <i class="fa-solid fa-door-open"></i>
                    <span>Manage Doors</span>
                </a>
                <a href="{{ route('access.sync.index') }}" class="action-btn action-sync">
                    <i class="fa-solid fa-arrows-rotate"></i>
                    <span>Sync Devices</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Row 3: Bottom Recent Events & System Health -->
    <div class="dashboard-bottom-row">
        <!-- Col 1: Recent Access Events -->
        <div class="dash-card recent-events-card">
            <div class="dash-card-header">
                <div class="dash-card-title">
                    <i class="fa-solid fa-list-ul card-title-icon"></i>
                    <h2>Recent Access Events</h2>
                </div>
                <a href="{{ route('events.index') }}" class="btn-view-all">
                    View All <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>
            
            <div class="recent-events-table-wrapper">
                <div class="events-table-header">
                    <div class="col-th-time">TIME</div>
                    <div class="col-th-personnel">PERSONNEL</div>
                    <div class="col-th-event">EVENT</div>
                    <div class="col-th-device">DEVICE / DOOR</div>
                    <div class="col-th-details">DETAILS</div>
                </div>

                @if(empty($recentEvents) || count($recentEvents) === 0)
                    <div class="events-empty-state">
                        <div class="empty-icon-circle">
                            <i class="fa-solid fa-fingerprint"></i>
                        </div>
                        <h3 class="empty-title">No recent events found</h3>
                        <p class="empty-desc">Access events will appear here once activity is recorded.</p>
                    </div>
                @else
                    <div class="events-table-body">
                        @foreach($recentEvents as $event)
                            <div class="events-table-row">
                                <div class="col-time">
                                    @php
                                        $time = $event['time'] ?? $event['Time'] ?? null;
                                        echo $time ? \Carbon\Carbon::parse($time)->format('M d, H:i') : 'Just now';
                                    @endphp
                                </div>
                                <div class="col-personnel">
                                    <span class="person-name">{{ $event['name'] ?? $event['Name'] ?? 'Unknown' }}</span>
                                    @if(isset($event['employeeNoString']) && $event['employeeNoString'])
                                        <span class="person-id">({{ $event['employeeNoString'] }})</span>
                                    @endif
                                </div>
                                <div class="col-event">
                                    <span class="event-pill">
                                        Type {{ $event['major'] ?? '1' }}-{{ $event['minor'] ?? '1' }}
                                    </span>
                                </div>
                                <div class="col-device">
                                    {{ $event['terminal_name'] ?? 'Main Entrance' }}
                                </div>
                                <div class="col-details">
                                    @if(isset($event['minor']) && in_array((int)$event['minor'], [1, 38, 75]))
                                        <span class="badge-success-pill">Fingerprint</span>
                                    @elseif(isset($event['minor']) && in_array((int)$event['minor'], [13, 14, 15, 21, 22]))
                                        <span class="badge-warning-pill">PIN</span>
                                    @else
                                        <span class="badge-neutral-pill">Access Granted</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <!-- Col 2: System Health -->
        <div class="dash-card system-health-card">
            <div class="dash-card-header">
                <div class="dash-card-title">
                    <i class="fa-solid fa-shield-halved card-title-icon"></i>
                    <h2>System Health</h2>
                </div>
            </div>

            <div class="health-summary">
                <div class="health-gauge">
                    <svg width="76" height="76" viewBox="0 0 76 76">
                        <circle cx="38" cy="38" r="32" stroke="#dcfce7" stroke-width="6" fill="none" />
                        <circle cx="38" cy="38" r="32" stroke="#10b981" stroke-width="6" fill="none" 
                                stroke-dasharray="201.06" stroke-dashoffset="0" stroke-linecap="round" />
                        <text x="38" y="44" text-anchor="middle" font-size="16" font-weight="800" fill="#047857" font-family="'Inter', sans-serif">100%</text>
                    </svg>
                </div>
                <div class="health-status-text">
                    <div class="status-heading">
                        <i class="fa-solid fa-circle-check"></i>
                        <span>System Operational</span>
                    </div>
                    <p class="status-sub">All core services are running properly.</p>
                </div>
            </div>

            <div class="health-services-list">
                <div class="health-service-item">
                    <div class="service-name">
                        <i class="fa-solid fa-circle-check"></i>
                        <span>Access Control Service</span>
                    </div>
                    <span class="health-pill-running">Running</span>
                </div>
                <div class="health-service-item">
                    <div class="service-name">
                        <i class="fa-solid fa-circle-check"></i>
                        <span>Database Service</span>
                    </div>
                    <span class="health-pill-running">Running</span>
                </div>
                <div class="health-service-item">
                    <div class="service-name">
                        <i class="fa-solid fa-circle-check"></i>
                        <span>Device Communication</span>
                    </div>
                    <span class="health-pill-running">Running</span>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
