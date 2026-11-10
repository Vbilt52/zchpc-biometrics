@extends('layouts.app')

@section('title', 'Executive Directorate Dashboard — ZCHPC')

@section('header', 'Executive Directorate Dashboard')

@section('content')
<div class="executive-dashboard">
    <!-- Executive Header Banner -->
    <div style="background:linear-gradient(135deg, #001b3d 0%, #0c70a2 100%);color:#ffffff;border-radius:0.75rem;padding:1.5rem 1.75rem;margin-bottom:1.5rem;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;">
        <div>
            <div style="font-size:0.85rem;text-transform:uppercase;letter-spacing:0.06em;color:#93c5fd;font-weight:600;margin-bottom:0.25rem;">
                <i class="fa-solid fa-building-shield"></i> Executive Oversight Portal
            </div>
            <h1 style="font-size:1.6rem;font-weight:700;margin:0;">ZCHPC Access Control &amp; Workforce Overview</h1>
            <p style="color:#e2e8f0;font-size:0.9rem;margin-top:0.35rem;">
                Real-time personnel attendance, high-security facility tracking, and organizational compliance metrics.
            </p>
        </div>

        <div style="display:flex;gap:0.75rem;align-items:center;">
            <a href="{{ route('attendance.export.pdf') }}" class="btn" style="padding:0.6rem 1.1rem;background:#ffffff;color:#001b3d;font-weight:600;font-size:0.85rem;border-radius:0.5rem;text-decoration:none;display:inline-flex;align-items:center;gap:0.4rem;box-shadow:0 1px 3px rgba(0,0,0,0.15);">
                <i class="fa-solid fa-file-pdf" style="color:#dc2626;"></i> Attendance PDF
            </a>
            <a href="{{ route('personnel.export.word') }}" class="btn" style="padding:0.6rem 1.1rem;background:rgba(255,255,255,0.15);color:#ffffff;font-weight:600;font-size:0.85rem;border-radius:0.5rem;text-decoration:none;display:inline-flex;align-items:center;gap:0.4rem;border:1px solid rgba(255,255,255,0.3);">
                <i class="fa-solid fa-file-word" style="color:#93c5fd;"></i> Personnel Directory
            </a>
        </div>
    </div>

    <!-- Executive KPI Grid -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:1rem;margin-bottom:1.5rem;">
        <div style="background:#ffffff;border-radius:0.75rem;padding:1.25rem;box-shadow:0 1px 3px rgba(0,0,0,0.07);border:1px solid #e2e8f0;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                <div>
                    <div style="font-size:0.8rem;text-transform:uppercase;color:#64748b;font-weight:600;">Staff On-Site Today</div>
                    <div style="font-size:1.8rem;font-weight:800;color:#047857;margin-top:0.25rem;">{{ $uniquePresentToday }}</div>
                    <div style="font-size:0.8rem;color:#64748b;margin-top:0.2rem;">out of {{ $totalPersonnel }} total registered</div>
                </div>
                <div style="width:44px;height:44px;border-radius:0.5rem;background:#dcfce7;color:#047857;display:flex;align-items:center;justify-content:center;font-size:1.25rem;">
                    <i class="fa-solid fa-user-check"></i>
                </div>
            </div>
        </div>

        <div style="background:#ffffff;border-radius:0.75rem;padding:1.25rem;box-shadow:0 1px 3px rgba(0,0,0,0.07);border:1px solid #e2e8f0;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                <div>
                    <div style="font-size:0.8rem;text-transform:uppercase;color:#64748b;font-weight:600;">Total Access Scans</div>
                    <div style="font-size:1.8rem;font-weight:800;color:#0c70a2;margin-top:0.25rem;">{{ $totalAccessToday }}</div>
                    <div style="font-size:0.8rem;color:#64748b;margin-top:0.2rem;">across all physical terminals</div>
                </div>
                <div style="width:44px;height:44px;border-radius:0.5rem;background:#e0f2fe;color:#0284c7;display:flex;align-items:center;justify-content:center;font-size:1.25rem;">
                    <i class="fa-solid fa-fingerprint"></i>
                </div>
            </div>
        </div>

        <div style="background:#ffffff;border-radius:0.75rem;padding:1.25rem;box-shadow:0 1px 3px rgba(0,0,0,0.07);border:1px solid #e2e8f0;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                <div>
                    <div style="font-size:0.8rem;text-transform:uppercase;color:#64748b;font-weight:600;">Datacenter Entries</div>
                    <div style="font-size:1.8rem;font-weight:800;color:#001b3d;margin-top:0.25rem;">{{ $serverRoomEntries + $upsRoomEntries }}</div>
                    <div style="font-size:0.8rem;color:#64748b;margin-top:0.2rem;">Server Room ({{ $serverRoomEntries }}) &bull; UPS ({{ $upsRoomEntries }})</div>
                </div>
                <div style="width:44px;height:44px;border-radius:0.5rem;background:#e2e8f0;color:#001b3d;display:flex;align-items:center;justify-content:center;font-size:1.25rem;">
                    <i class="fa-solid fa-server"></i>
                </div>
            </div>
        </div>

        <div style="background:#ffffff;border-radius:0.75rem;padding:1.25rem;box-shadow:0 1px 3px rgba(0,0,0,0.07);border:1px solid #e2e8f0;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                <div>
                    <div style="font-size:0.8rem;text-transform:uppercase;color:#64748b;font-weight:600;">Security Anomalies</div>
                    <div style="font-size:1.8rem;font-weight:800;color:{{ $failedAttemptsToday > 0 ? '#991b1b' : '#047857' }};margin-top:0.25rem;">{{ $failedAttemptsToday }}</div>
                    <div style="font-size:0.8rem;color:#64748b;margin-top:0.2rem;">{{ $offlineTerminals }} offline device(s)</div>
                </div>
                <div style="width:44px;height:44px;border-radius:0.5rem;background:{{ $failedAttemptsToday > 0 ? '#fee2e2' : '#dcfce7' }};color:{{ $failedAttemptsToday > 0 ? '#991b1b' : '#047857' }};display:flex;align-items:center;justify-content:center;font-size:1.25rem;">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content 2-Column Grid -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
        <!-- Left: Department Attendance Breakdown -->
        <div style="background:#ffffff;border-radius:0.75rem;padding:1.5rem;box-shadow:0 1px 3px rgba(0,0,0,0.07);border:1px solid #e2e8f0;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;">
                <h2 style="font-size:1.1rem;color:#0f172a;font-weight:700;">
                    <i class="fa-solid fa-chart-pie" style="color:#0c70a2;"></i> Department Attendance Performance
                </h2>
                <a href="{{ route('attendance.index') }}" style="font-size:0.85rem;color:#0c70a2;text-decoration:none;font-weight:600;">
                    Full Register <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>

            <div style="display:flex;flex-direction:column;gap:1rem;">
                @foreach($deptStats as $dept)
                    <div>
                        <div style="display:flex;justify-content:space-between;font-size:0.875rem;margin-bottom:0.35rem;">
                            <span style="font-weight:600;color:#0f172a;">{{ $dept['name'] }}</span>
                            <span style="color:#64748b;">
                                <strong>{{ $dept['present'] }}</strong> / {{ $dept['total'] }} present
                                ({{ $dept['rate'] }}%)
                            </span>
                        </div>
                        <div style="height:8px;background:#f1f5f9;border-radius:4px;overflow:hidden;">
                            <div style="width:{{ $dept['rate'] }}%;height:100%;background:{{ $dept['rate'] >= 75 ? '#047857' : ($dept['rate'] >= 50 ? '#d97706' : '#991b1b') }};border-radius:4px;"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <!-- Right: Critical Facilities & Security Alerts -->
        <div style="display:flex;flex-direction:column;gap:1.5rem;">
            <!-- Datacenter Access Card -->
            <div style="background:#ffffff;border-radius:0.75rem;padding:1.5rem;box-shadow:0 1px 3px rgba(0,0,0,0.07);border:1px solid #e2e8f0;">
                <h2 style="font-size:1.1rem;color:#0f172a;font-weight:700;margin-bottom:1rem;">
                    <i class="fa-solid fa-lock" style="color:#001b3d;"></i> Restricted Datacenter Areas
                </h2>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                    <div style="padding:1rem;background:#f8fafc;border-radius:0.5rem;border:1px solid #e2e8f0;">
                        <div style="font-size:0.8rem;text-transform:uppercase;color:#64748b;font-weight:600;">Server Room</div>
                        <div style="font-size:1.4rem;font-weight:700;color:#001b3d;margin-top:0.25rem;">{{ $serverRoomEntries }}</div>
                        <div style="font-size:0.8rem;color:#047857;margin-top:0.2rem;"><i class="fa-solid fa-shield-check"></i> High Security Zone</div>
                    </div>

                    <div style="padding:1rem;background:#f8fafc;border-radius:0.5rem;border:1px solid #e2e8f0;">
                        <div style="font-size:0.8rem;text-transform:uppercase;color:#64748b;font-weight:600;">UPS Room</div>
                        <div style="font-size:1.4rem;font-weight:700;color:#001b3d;margin-top:0.25rem;">{{ $upsRoomEntries }}</div>
                        <div style="font-size:0.8rem;color:#047857;margin-top:0.2rem;"><i class="fa-solid fa-bolt"></i> Power Infrastructure</div>
                    </div>
                </div>
            </div>

            <!-- Security & Hardware Status -->
            <div style="background:#ffffff;border-radius:0.75rem;padding:1.5rem;box-shadow:0 1px 3px rgba(0,0,0,0.07);border:1px solid #e2e8f0;">
                <h2 style="font-size:1.1rem;color:#0f172a;font-weight:700;margin-bottom:1rem;">
                    <i class="fa-solid fa-shield-halved" style="color:#047857;"></i> Security &amp; Terminal Health
                </h2>

                <div style="display:flex;flex-direction:column;gap:0.75rem;font-size:0.875rem;">
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:0.6rem 0.75rem;background:#f8fafc;border-radius:0.35rem;">
                        <span><i class="fa-solid fa-door-closed" style="color:#0c70a2;"></i> Physical Access Points</span>
                        <span style="font-weight:700;color:#0f172a;">4 Controlled Doors</span>
                    </div>

                    <div style="display:flex;justify-content:space-between;align-items:center;padding:0.6rem 0.75rem;background:#f8fafc;border-radius:0.35rem;">
                        <span><i class="fa-solid fa-server" style="color:#0c70a2;"></i> Hikvision Terminals</span>
                        <span style="font-weight:700;color:{{ $offlineTerminals === 0 ? '#047857' : '#991b1b' }};">
                            {{ $offlineTerminals === 0 ? 'All 4 Online' : $offlineTerminals . ' Offline' }}
                        </span>
                    </div>

                    <div style="display:flex;justify-content:space-between;align-items:center;padding:0.6rem 0.75rem;background:#f8fafc;border-radius:0.35rem;">
                        <span><i class="fa-solid fa-triangle-exclamation" style="color:#d97706;"></i> Unacknowledged Alerts</span>
                        <span style="font-weight:700;color:{{ $openAlerts === 0 ? '#047857' : '#d97706' }};">
                            {{ $openAlerts }}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

