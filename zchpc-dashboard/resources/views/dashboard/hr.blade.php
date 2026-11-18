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

        <div style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;">
            <a href="{{ route('personnel.create') }}" style="padding:0.6rem 1.25rem;background:#047857;color:#ffffff;border-radius:0.5rem;font-weight:600;font-size:0.9rem;text-decoration:none;display:inline-flex;align-items:center;gap:0.5rem;box-shadow:0 1px 2px rgba(0,0,0,0.1);">
                <i class="fa-solid fa-user-plus"></i> Add New Personnel
            </a>
            <a href="{{ route('attendance.index') }}" style="padding:0.6rem 1.25rem;background:#0c70a2;color:#ffffff;border-radius:0.5rem;font-weight:600;font-size:0.9rem;text-decoration:none;display:inline-flex;align-items:center;gap:0.5rem;">
                <i class="fa-solid fa-clipboard-user"></i> Attendance Register
            </a>
        </div>
    </div>

    <!-- HR KPI Grid -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(min(200px, 100%), 1fr));gap:1rem;margin-bottom:1.5rem;">
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

    <section class="hr-activity" data-hr-activity data-url="{{ route('dashboard.hr.activity') }}" aria-labelledby="hr-activity-title">
        <div class="hr-activity-heading">
            <div><h2 id="hr-activity-title">Today's door activity</h2>
                <p>{{ $activity['date'] }} · {{ $activity['timezone'] }} · Latest 50 recorded events</p>
            </div>
            <div class="hr-activity-actions">
                <button type="button" class="btn btn-secondary" data-activity-refresh>Refresh</button>
                <a class="btn btn-secondary" href="{{ route('events.index') }}">Full log &amp; exports</a>
            </div>
        </div>
        <p class="hr-feed-status" role="status" data-activity-status>Updated {{ $activity['updated_at'] }}. Refreshes every 10 seconds.</p>
        <div class="hr-terminal-status" data-activity-terminals>
            @foreach($activity['terminals'] as $terminal)
                <div class="hr-terminal"><strong>{{ $terminal['name'] }}</strong><span class="hr-feed-badge" data-state="{{ $terminal['status'] }}">{{ $terminal['status'] }}</span><small>Logs through {{ $terminal['through'] ?? 'not yet collected' }}</small></div>
            @endforeach
        </div>
        <p class="hr-feed-note">Terminal logs are collected automatically. Offline or delayed doors may have activity that has not arrived yet. A granted result confirms authorization; entry direction is shown only when reported by the device.</p>
        <div class="table-wrapper hr-activity-table" tabindex="0" aria-label="Today's door activity">
            <table><thead><tr><th>Time</th><th>Employee</th><th>Door / terminal</th><th>Method</th><th>Result</th><th>Direction</th></tr></thead>
                <tbody data-activity-rows>
                @forelse($activity['events'] as $event)
                    <tr><td data-label="Time">{{ $event['time'] }}</td>
                        <td data-label="Employee"><strong>{{ $event['name'] }}</strong><small>{{ $event['employee_id'] }}</small></td>
                        <td data-label="Door / terminal"><strong>{{ $event['terminal'] }}</strong><small>{{ $event['door'] }}</small></td>
                        <td data-label="Method">{{ $event['method'] }}</td>
                        <td data-label="Result"><span class="hr-feed-badge" data-state="{{ $event['outcome'] }}">{{ $event['outcome'] }}</span></td>
                        <td data-label="Direction">{{ $event['direction'] }}</td></tr>
                @empty
                    <tr><td colspan="6" class="hr-activity-empty">No personnel activity received today.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
    <div class="hr-sync-section">
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
                                    No personnel updates are waiting in the sync queue.
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


@push('styles')
<link rel="stylesheet" href="{{ asset('css/hr-activity.css') }}?v={{ filemtime(public_path('css/hr-activity.css')) }}">
@endpush
@push('scripts')
<script src="{{ asset('js/hr-activity.js') }}?v={{ filemtime(public_path('js/hr-activity.js')) }}" defer></script>
@endpush
