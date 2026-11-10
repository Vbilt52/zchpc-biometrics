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
            <span id="dashboard-date-time">{{ $formattedDateTime ?? now()->format('D, d M Y | H:i') }}</span>
        </div>
    </div>

    @if(auth()->user()?->isFullAdmin())
        <div style="display:flex;gap:0.5rem;margin-bottom:1.25rem;flex-wrap:wrap;">
            <a href="/" style="padding:0.45rem 1rem;background:#0c70a2;color:#ffffff;border-radius:0.5rem;font-weight:600;font-size:0.85rem;text-decoration:none;display:inline-flex;align-items:center;gap:0.4rem;box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                <i class="fa-solid fa-table-cells-large"></i> Main Overview
            </a>
            <a href="{{ route('dashboard.executive') }}" style="padding:0.45rem 1rem;background:#ffffff;color:#475569;border:1px solid #cbd5e1;border-radius:0.5rem;font-weight:600;font-size:0.85rem;text-decoration:none;display:inline-flex;align-items:center;gap:0.4rem;">
                <i class="fa-solid fa-chart-line"></i> Executive Oversight
            </a>
            <a href="{{ route('dashboard.hr') }}" style="padding:0.45rem 1rem;background:#ffffff;color:#475569;border:1px solid #cbd5e1;border-radius:0.5rem;font-weight:600;font-size:0.85rem;text-decoration:none;display:inline-flex;align-items:center;gap:0.4rem;">
                <i class="fa-solid fa-users-viewfinder"></i> HR Workforce
            </a>
            <a href="{{ route('dashboard.audit') }}" style="padding:0.45rem 1rem;background:#ffffff;color:#475569;border:1px solid #cbd5e1;border-radius:0.5rem;font-weight:600;font-size:0.85rem;text-decoration:none;display:inline-flex;align-items:center;gap:0.4rem;">
                <i class="fa-solid fa-shield-halved"></i> Audit &amp; Compliance
            </a>
        </div>
    @endif

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
                        <span class="kpi-bold-num" id="kpi-online-terminals">{{ $onlineTerminals }}</span>
                        <span class="kpi-total-num" id="kpi-total-terminals">/ {{ $totalTerminals }} Total</span>
                    </div>
                    <div class="terminal-progress-trough">
                        <div class="terminal-progress-bar" id="kpi-terminal-progress-bar" style="width: {{ $terminalProgress ?? ($totalTerminals > 0 ? max(20, min(100, ($onlineTerminals / $totalTerminals) * 100)) : 50) }}%;"></div>
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
                    <div class="kpi-stat-value text-dark" id="kpi-total-persons">
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
                    <div class="kpi-stat-value text-dark" id="kpi-events-today">
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
                    <div class="kpi-stat-value text-dark" id="kpi-controlled-doors">
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
                <div class="chart-y-axis" id="chart-y-axis">
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

                        <div class="chart-bars-wrapper" id="chart-bars-wrapper">
                            @foreach($chartDays as $bar)
                                <div class="chart-bar-slot">
                                    <div class="chart-bar-fill" style="height: {{ $bar['height'] }}%;" title="{{ $bar['day'] }}: {{ $bar['count'] }} events"></div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <div class="chart-x-labels-row" id="chart-x-labels">
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
                    <span class="status-count" id="status-online-count">{{ $onlineTerminals }}</span>
                </div>
                <div class="status-item">
                    <div class="status-item-left">
                        <i class="fa-solid fa-circle-xmark status-icon-offline"></i>
                        <span class="status-label">Offline</span>
                    </div>
                    <span class="status-count" id="status-offline-count">{{ $offlineTerminals }}</span>
                </div>
                <div class="status-item">
                    <div class="status-item-left">
                        <i class="fa-solid fa-server status-icon-total"></i>
                        <span class="status-label">Total Devices</span>
                    </div>
                    <span class="status-count" id="status-total-count">{{ $totalTerminals }}</span>
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
            
            <div class="recent-events-table-wrapper" id="recent-events-container">
                <div class="events-table-header">
                    <div class="col-th-time">TIME</div>
                    <div class="col-th-personnel">PERSONNEL</div>
                    <div class="col-th-event">METHOD</div>
                    <div class="col-th-device">DEVICE / DOOR</div>
                    <div class="col-th-details">RESULT</div>
                </div>

                <div class="events-table-body" id="recent-events-body" style="{{ (empty($recentEvents) || count($recentEvents) === 0) ? 'display:none;' : '' }}">
                    @if(!empty($recentEvents))
                        @foreach($recentEvents as $event)
                            <div class="events-table-row">
                                <div class="col-time">
                                    <span style="font-weight:700;color:var(--zchpc-secondary);display:block;">{{ $event['time_formatted'] ?? 'Just now' }}</span>
                                    @if(!empty($event['time_relative']))
                                        <small style="color:var(--zchpc-text-muted);font-size:.74rem;">{{ $event['time_relative'] }}</small>
                                    @endif
                                </div>
                                <div class="col-personnel">
                                    <span class="person-name">{{ $event['name'] ?? 'Unknown' }}</span>
                                    @if(!empty($event['employeeNoString']))
                                        <span class="person-id">({{ $event['employeeNoString'] }})</span>
                                    @endif
                                </div>
                                <div class="col-event">
                                    <span class="event-pill">
                                        {{ $event['method'] ?? 'System' }}
                                    </span>
                                </div>
                                <div class="col-device">
                                    {{ $event['terminal_name'] ?? 'Main Entrance' }}
                                </div>
                                <div class="col-details">
                                    <span class="badge {{ $event['outcome_class'] ?? 'badge-muted' }}">{{ $event['outcome'] ?? 'Granted' }}</span>
                                </div>
                            </div>
                        @endforeach
                    @endif
                </div>

                <div class="events-empty-state" id="recent-events-empty" style="{{ (!empty($recentEvents) && count($recentEvents) > 0) ? 'display:none;' : '' }}">
                    <div class="empty-icon-circle">
                        <i class="fa-solid fa-fingerprint"></i>
                    </div>
                    <h3 class="empty-title">No recent events found</h3>
                    <p class="empty-desc">Access events will appear here once activity is recorded.</p>
                </div>
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
                        <circle id="health-gauge-circle" cx="38" cy="38" r="32" stroke="#047857" stroke-width="6" fill="none" 
                                stroke-dasharray="201.06" stroke-dashoffset="{{ 201.06 * (1 - (($healthPercent ?? 100) / 100)) }}" stroke-linecap="round" />
                        <text id="health-gauge-text" x="38" y="44" text-anchor="middle" font-size="16" font-weight="800" fill="#047857" font-family="'Inter', sans-serif">{{ $healthPercent ?? 100 }}%</text>
                    </svg>
                </div>
                <div class="health-status-text">
                    <div class="status-heading">
                        <i class="fa-solid fa-circle-check" id="health-status-icon" style="color:#047857;"></i>
                        <span id="health-status-title">{{ $healthTitle ?? 'System Operational' }}</span>
                    </div>
                    <p class="status-sub" id="health-status-sub">{{ $healthSubtitle ?? 'All core services are running properly.' }}</p>
                </div>
            </div>

            <div class="health-services-list">
                <div class="health-service-item">
                    <div class="service-name">
                        <i class="fa-solid fa-circle-check" style="color:#047857;"></i>
                        <span>Access Control Service</span>
                    </div>
                    <span class="health-pill-running">Running</span>
                </div>
                <div class="health-service-item">
                    <div class="service-name">
                        <i class="fa-solid fa-circle-check" style="color:#047857;"></i>
                        <span>Database Service</span>
                    </div>
                    <span class="health-pill-running">Running</span>
                </div>
                <div class="health-service-item">
                    <div class="service-name">
                        <i class="fa-solid fa-circle-check" style="color:#047857;" id="service-comm-icon"></i>
                        <span>Device Communication</span>
                    </div>
                    <span class="health-pill-running" id="service-comm-pill">{{ ($offlineTerminals ?? 0) === 0 ? 'Running' : ($onlineTerminals ?? 0).' Online' }}</span>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    function refreshDashboardLive() {
        fetch('{{ route("dashboard.live-data") }}', {
            headers: { 'Accept': 'application/json' }
        })
        .then(response => {
            if (!response.ok) return null;
            return response.json();
        })
        .then(data => {
            if (!data) return;

            // Update KPIs
            const onlineEl = document.getElementById('kpi-online-terminals');
            if (onlineEl) onlineEl.textContent = data.onlineTerminals;

            const totalTerminalsEl = document.getElementById('kpi-total-terminals');
            if (totalTerminalsEl) totalTerminalsEl.textContent = '/ ' + data.totalTerminals + ' Total';

            const progressBar = document.getElementById('kpi-terminal-progress-bar');
            if (progressBar && data.terminalProgress !== undefined) {
                progressBar.style.width = data.terminalProgress + '%';
            }

            const totalPersonsEl = document.getElementById('kpi-total-persons');
            if (totalPersonsEl) totalPersonsEl.textContent = Number(data.totalPersons).toLocaleString();

            const eventsTodayEl = document.getElementById('kpi-events-today');
            if (eventsTodayEl) eventsTodayEl.textContent = Number(data.eventsTodayCount).toLocaleString();

            const controlledDoorsEl = document.getElementById('kpi-controlled-doors');
            if (controlledDoorsEl) controlledDoorsEl.textContent = Number(data.controlledDoors).toLocaleString();

            const dateTimeEl = document.getElementById('dashboard-date-time');
            if (dateTimeEl && data.formattedDateTime) dateTimeEl.textContent = data.formattedDateTime;

            // Update Device Status block
            const statusOnlineEl = document.getElementById('status-online-count');
            if (statusOnlineEl) statusOnlineEl.textContent = data.onlineTerminals;

            const statusOfflineEl = document.getElementById('status-offline-count');
            if (statusOfflineEl) statusOfflineEl.textContent = data.offlineTerminals;

            const statusTotalEl = document.getElementById('status-total-count');
            if (statusTotalEl) statusTotalEl.textContent = data.totalTerminals;

            // Update 7-Day Chart
            if (data.chartDays && Array.isArray(data.chartDays)) {
                const barsContainer = document.getElementById('chart-bars-wrapper');
                const xLabelsContainer = document.getElementById('chart-x-labels');
                const yAxis = document.getElementById('chart-y-axis');

                if (yAxis && data.chartMax) {
                    yAxis.innerHTML = `
                        <span>${Math.round(data.chartMax)}</span>
                        <span>${Math.round(data.chartMax * 0.8)}</span>
                        <span>${Math.round(data.chartMax * 0.6)}</span>
                        <span>${Math.round(data.chartMax * 0.4)}</span>
                        <span>${Math.round(data.chartMax * 0.2)}</span>
                        <span>0</span>
                    `;
                }

                if (barsContainer) {
                    barsContainer.innerHTML = data.chartDays.map(bar => `
                        <div class="chart-bar-slot">
                            <div class="chart-bar-fill" style="height: ${bar.height}%;" title="${escapeHtml(bar.day)}: ${bar.count} events"></div>
                        </div>
                    `).join('');
                }

                if (xLabelsContainer) {
                    xLabelsContainer.innerHTML = data.chartDays.map(bar => `
                        <div class="chart-x-label-item">${escapeHtml(bar.day)}</div>
                    `).join('');
                }
            }

            // Update System Health
            if (data.healthPercent !== undefined) {
                const gaugeCircle = document.getElementById('health-gauge-circle');
                const gaugeText = document.getElementById('health-gauge-text');
                const statusTitle = document.getElementById('health-status-title');
                const statusSub = document.getElementById('health-status-sub');
                const commPill = document.getElementById('service-comm-pill');

                if (gaugeCircle) {
                    const circumference = 201.06;
                    gaugeCircle.style.strokeDashoffset = circumference * (1 - (data.healthPercent / 100));
                    const strokeColor = data.healthPercent === 100 ? '#047857' : (data.healthPercent >= 75 ? '#d97706' : '#991b1b');
                    gaugeCircle.setAttribute('stroke', strokeColor);
                    if (gaugeText) {
                        gaugeText.textContent = data.healthPercent + '%';
                        gaugeText.setAttribute('fill', strokeColor);
                    }
                }
                if (statusTitle && data.healthTitle) statusTitle.textContent = data.healthTitle;
                if (statusSub && data.healthSubtitle) statusSub.textContent = data.healthSubtitle;
                if (commPill) {
                    commPill.textContent = data.offlineTerminals === 0 ? 'Running' : data.onlineTerminals + ' Online';
                }
            }

            // Update Recent Events
            const eventsBody = document.getElementById('recent-events-body');
            const eventsEmpty = document.getElementById('recent-events-empty');

            if (data.recentEvents && Array.isArray(data.recentEvents) && data.recentEvents.length > 0) {
                if (eventsEmpty) eventsEmpty.style.display = 'none';
                if (eventsBody) {
                    eventsBody.style.display = 'block';
                    eventsBody.innerHTML = data.recentEvents.map(event => `
                        <div class="events-table-row">
                            <div class="col-time">
                                <span style="font-weight:700;color:var(--zchpc-secondary);display:block;">${escapeHtml(event.time_formatted || 'Just now')}</span>
                                ${event.time_relative ? `<small style="color:var(--zchpc-text-muted);font-size:.74rem;">${escapeHtml(event.time_relative)}</small>` : ''}
                            </div>
                            <div class="col-personnel">
                                <span class="person-name">${escapeHtml(event.name || 'Unknown')}</span>
                                ${event.employeeNoString ? `<span class="person-id">(${escapeHtml(event.employeeNoString)})</span>` : ''}
                            </div>
                            <div class="col-event">
                                <span class="event-pill">${escapeHtml(event.method || 'System')}</span>
                            </div>
                            <div class="col-device">${escapeHtml(event.terminal_name || 'Main Entrance')}</div>
                            <div class="col-details">
                                <span class="badge ${escapeHtml(event.outcome_class || 'badge-muted')}">${escapeHtml(event.outcome || 'Granted')}</span>
                            </div>
                        </div>
                    `).join('');
                }
            } else {
                if (eventsBody) eventsBody.style.display = 'none';
                if (eventsEmpty) eventsEmpty.style.display = 'flex';
            }
        })
        .catch(() => {});
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>"']/g, function(m) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
        });
    }

    // Refresh every 6 seconds
    setInterval(refreshDashboardLive, 6000);
});
</script>
@endpush
