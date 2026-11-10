@extends('layouts.app')

@section('title', 'Human Resources Dashboard — ZCHPC')

@section('header', 'Human Resources Workforce Portal')

@section('content')
<div class="hr-dashboard">
    <!-- Header Summary -->
    <div style="background:#ffffff;border-radius:0.75rem;padding:1.5rem 1.75rem;margin-bottom:1.5rem;box-shadow:0 1px 3px rgba(0,0,0,0.07);border:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;">
        <div>
            <h1 style="font-size:1.5rem;font-weight:700;color:#0f172a;margin:0;">Workforce &amp; Personnel Operations</h1>
            <p style="color:#64748b;font-size:0.875rem;margin-top:0.25rem;">
                Manage staff registrations, track attendance check-ins, and monitor controller synchronization.
            </p>
        </div>

        <div style="display:flex;gap:0.75rem;align-items:center;">
            <a href="{{ route('personnel.create') }}" style="padding:0.6rem 1.25rem;background:#047857;color:#ffffff;border-radius:0.5rem;font-weight:600;font-size:0.9rem;text-decoration:none;display:inline-flex;align-items:center;gap:0.5rem;box-shadow:0 1px 2px rgba(0,0,0,0.1);">
                <i class="fa-solid fa-user-plus"></i> Add New Personnel
            </a>
            <a href="{{ route('attendance.index') }}" style="padding:0.6rem 1.25rem;background:#0c70a2;color:#ffffff;border-radius:0.5rem;font-weight:600;font-size:0.9rem;text-decoration:none;display:inline-flex;align-items:center;gap:0.5rem;">
                <i class="fa-solid fa-clipboard-user"></i> Attendance Register
            </a>
        </div>
    </div>

    <!-- HR KPI Grid -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:1rem;margin-bottom:1.5rem;">
        <div style="background:#ffffff;border-radius:0.75rem;padding:1.25rem;box-shadow:0 1px 3px rgba(0,0,0,0.07);border:1px solid #e2e8f0;">
            <div style="font-size:0.8rem;text-transform:uppercase;color:#64748b;font-weight:600;">Registered Personnel</div>
            <div style="font-size:1.8rem;font-weight:800;color:#0f172a;margin-top:0.25rem;">{{ $totalPersonnel }}</div>
            <div style="font-size:0.8rem;color:#047857;margin-top:0.2rem;">{{ $activePersonnel }} active records</div>
        </div>

        <div style="background:#ffffff;border-radius:0.75rem;padding:1.25rem;box-shadow:0 1px 3px rgba(0,0,0,0.07);border:1px solid #e2e8f0;">
            <div style="font-size:0.8rem;text-transform:uppercase;color:#64748b;font-weight:600;">Enrolled Biometrics</div>
            <div style="font-size:1.8rem;font-weight:800;color:#0c70a2;margin-top:0.25rem;">{{ $enrolledFingerprints }}</div>
            <div style="font-size:0.8rem;color:#64748b;margin-top:0.2rem;">staff with verified fingerprints</div>
        </div>

        <div style="background:#ffffff;border-radius:0.75rem;padding:1.25rem;box-shadow:0 1px 3px rgba(0,0,0,0.07);border:1px solid #e2e8f0;">
            <div style="font-size:0.8rem;text-transform:uppercase;color:#64748b;font-weight:600;">Pending Enrollments</div>
            <div style="font-size:1.8rem;font-weight:800;color:#d97706;margin-top:0.25rem;">{{ max(0, $totalPersonnel - $enrolledFingerprints) }}</div>
            <div style="font-size:0.8rem;color:#64748b;margin-top:0.2rem;">awaiting biometric capture</div>
        </div>

        <div style="background:#ffffff;border-radius:0.75rem;padding:1.25rem;box-shadow:0 1px 3px rgba(0,0,0,0.07);border:1px solid #e2e8f0;">
            <div style="font-size:0.8rem;text-transform:uppercase;color:#64748b;font-weight:600;">Terminal Sync Queue</div>
            <div style="font-size:1.8rem;font-weight:800;color:{{ $pendingSyncsCount > 0 ? '#d97706' : '#047857' }};margin-top:0.25rem;">{{ $pendingSyncsCount }}</div>
            <div style="font-size:0.8rem;color:#64748b;margin-top:0.2rem;">updates queued for hardware</div>
        </div>
    </div>

    <!-- 2-Column Section -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
        <!-- Left: Today's Sign-ins / Clock-ins -->
        <div style="background:#ffffff;border-radius:0.75rem;box-shadow:0 1px 3px rgba(0,0,0,0.07);border:1px solid #e2e8f0;overflow:hidden;">
            <div style="padding:1.25rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;display:flex;justify-content:space-between;align-items:center;">
                <h3 style="font-size:1rem;color:#0f172a;font-weight:700;">
                    <i class="fa-solid fa-clock" style="color:#0c70a2;"></i> Today's Personnel Clock-ins (Recent)
                </h3>
                <a href="{{ route('attendance.index') }}" style="font-size:0.8rem;color:#0c70a2;text-decoration:none;font-weight:600;">View All</a>
            </div>

            <div style="max-height:450px;overflow-y:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:0.85rem;text-align:left;">
                    <thead>
                        <tr style="background:#f1f5f9;color:#475569;font-size:0.75rem;text-transform:uppercase;">
                            <th style="padding:0.65rem 0.85rem;">Time</th>
                            <th style="padding:0.65rem 0.85rem;">Employee</th>
                            <th style="padding:0.65rem 0.85rem;">Method</th>
                            <th style="padding:0.65rem 0.85rem;">Location</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentClockIns as $clock)
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:0.65rem 0.85rem;font-weight:700;color:#0f172a;">
                                    {{ $clock->occurred_at?->timezone(config('attendance.timezone'))->format('H:i:s') }}
                                </td>
                                <td style="padding:0.65rem 0.85rem;">
                                    <div style="font-weight:600;color:#0f172a;">{{ $clock->person_name ?: 'Employee #' . $clock->employee_no }}</div>
                                    <div style="font-size:0.75rem;color:#64748b;">ID: {{ $clock->employee_no }}</div>
                                </td>
                                <td style="padding:0.65rem 0.85rem;">
                                    <span style="display:inline-block;padding:0.15rem 0.45rem;border-radius:4px;font-size:0.72rem;background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;">
                                        {{ $clock->methodLabel() }}
                                    </span>
                                </td>
                                <td style="padding:0.65rem 0.85rem;color:#475569;">
                                    {{ $clock->terminal?->name ?? 'Main Entrance' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" style="padding:2rem;text-align:center;color:#64748b;">
                                    No clock-in activity recorded yet today.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Right: Terminal Sync Health for Managed Personnel -->
        <div style="background:#ffffff;border-radius:0.75rem;box-shadow:0 1px 3px rgba(0,0,0,0.07);border:1px solid #e2e8f0;overflow:hidden;">
            <div style="padding:1.25rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;display:flex;justify-content:space-between;align-items:center;">
                <h3 style="font-size:1rem;color:#0f172a;font-weight:700;">
                    <i class="fa-solid fa-arrows-rotate" style="color:#047857;"></i> Personnel Hardware Synchronization Status
                </h3>
                <span style="font-size:0.8rem;color:#64748b;">{{ $pendingSyncsCount }} Pending</span>
            </div>

            <div style="max-height:450px;overflow-y:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:0.85rem;text-align:left;">
                    <thead>
                        <tr style="background:#f1f5f9;color:#475569;font-size:0.75rem;text-transform:uppercase;">
                            <th style="padding:0.65rem 0.85rem;">Personnel</th>
                            <th style="padding:0.65rem 0.85rem;">Terminal</th>
                            <th style="padding:0.65rem 0.85rem;">Status</th>
                            <th style="padding:0.65rem 0.85rem;">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($pendingSyncs as $sync)
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:0.65rem 0.85rem;font-weight:600;color:#0f172a;">
                                    {{ $sync->personnel?->name ?? 'Employee #' . $sync->personnel?->employee_no }}
                                </td>
                                <td style="padding:0.65rem 0.85rem;color:#334155;">
                                    {{ $sync->terminal?->name ?? 'Terminal' }}
                                </td>
                                <td style="padding:0.65rem 0.85rem;">
                                    <span style="display:inline-block;padding:0.15rem 0.45rem;border-radius:4px;font-size:0.72rem;background:#fef3c7;color:#92400e;font-weight:600;">
                                        Pending Sync
                                    </span>
                                </td>
                                <td style="padding:0.65rem 0.85rem;color:#64748b;font-size:0.75rem;">
                                    {{ Str::limit($sync->last_error ?: 'Queued for controller write', 35) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" style="padding:2.5rem;text-align:center;color:#047857;">
                                    <i class="fa-solid fa-circle-check" style="font-size:2rem;color:#047857;margin-bottom:0.5rem;display:block;"></i>
                                    All personnel records are fully synchronized across all 4 door controllers.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

