@extends('layouts.app')

@section('title', 'Internal Audit & Security Oversight — ZCHPC')

@section('header', 'Internal Audit & Security Dashboard')

@section('content')
<div class="audit-dashboard">
    <!-- Header Summary -->
    <div style="background:#ffffff;border-radius:0.75rem;padding:1.5rem 1.75rem;margin-bottom:1.5rem;box-shadow:0 1px 3px rgba(0,0,0,0.07);border:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;">
        <div>
            <div style="font-size:0.8rem;text-transform:uppercase;color:#d97706;font-weight:700;letter-spacing:0.04em;margin-bottom:0.25rem;">
                <i class="fa-solid fa-shield-halved"></i> Read-Only Compliance Portal
            </div>
            <h1 style="font-size:1.5rem;font-weight:700;color:#0f172a;margin:0;">Audit Trail &amp; Access Oversight</h1>
            <p style="color:#64748b;font-size:0.875rem;margin-top:0.25rem;">
                Immutable logging of user identity modifications, device writes, access security events, and operator activity.
            </p>
        </div>

        <div style="display:flex;gap:0.75rem;align-items:center;">
            <a href="{{ route('access.audit.index') }}" style="padding:0.6rem 1.25rem;background:#0c70a2;color:#ffffff;border-radius:0.5rem;font-weight:600;font-size:0.9rem;text-decoration:none;display:inline-flex;align-items:center;gap:0.5rem;">
                <i class="fa-solid fa-list-check"></i> Complete Audit Log
            </a>
            <a href="{{ route('events.export.pdf') }}" style="padding:0.6rem 1.25rem;background:#f1f5f9;color:#0f172a;border:1px solid #cbd5e1;border-radius:0.5rem;font-weight:600;font-size:0.9rem;text-decoration:none;display:inline-flex;align-items:center;gap:0.5rem;">
                <i class="fa-solid fa-file-pdf" style="color:#dc2626;"></i> Access Events PDF
            </a>
        </div>
    </div>

    <!-- Audit Stats KPI Grid -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:1rem;margin-bottom:1.5rem;">
        <div style="background:#ffffff;border-radius:0.75rem;padding:1.25rem;box-shadow:0 1px 3px rgba(0,0,0,0.07);border:1px solid #e2e8f0;">
            <div style="font-size:0.8rem;text-transform:uppercase;color:#64748b;font-weight:600;">System Audit Logs Today</div>
            <div style="font-size:1.8rem;font-weight:800;color:#0f172a;margin-top:0.25rem;">{{ $stats['audit_today'] }}</div>
            <div style="font-size:0.8rem;color:#047857;margin-top:0.2rem;"><i class="fa-solid fa-shield-check"></i> Immutable Records</div>
        </div>

        <div style="background:#ffffff;border-radius:0.75rem;padding:1.25rem;box-shadow:0 1px 3px rgba(0,0,0,0.07);border:1px solid #e2e8f0;">
            <div style="font-size:0.8rem;text-transform:uppercase;color:#64748b;font-weight:600;">Hardware Sync Events Today</div>
            <div style="font-size:1.8rem;font-weight:800;color:#0c70a2;margin-top:0.25rem;">{{ $stats['sync_events_today'] }}</div>
            <div style="font-size:0.8rem;color:#64748b;margin-top:0.2rem;">reads &amp; writes to controllers</div>
        </div>

        <div style="background:#ffffff;border-radius:0.75rem;padding:1.25rem;box-shadow:0 1px 3px rgba(0,0,0,0.07);border:1px solid #e2e8f0;">
            <div style="font-size:0.8rem;text-transform:uppercase;color:#64748b;font-weight:600;">Sync Failures Today</div>
            <div style="font-size:1.8rem;font-weight:800;color:{{ $stats['failed_syncs'] > 0 ? '#991b1b' : '#047857' }};margin-top:0.25rem;">{{ $stats['failed_syncs'] }}</div>
            <div style="font-size:0.8rem;color:#64748b;margin-top:0.2rem;">hardware rejections</div>
        </div>

        <div style="background:#ffffff;border-radius:0.75rem;padding:1.25rem;box-shadow:0 1px 3px rgba(0,0,0,0.07);border:1px solid #e2e8f0;">
            <div style="font-size:0.8rem;text-transform:uppercase;color:#64748b;font-weight:600;">Failed Access / Denials Today</div>
            <div style="font-size:1.8rem;font-weight:800;color:{{ $stats['failed_access_today'] > 0 ? '#991b1b' : '#047857' }};margin-top:0.25rem;">{{ $stats['failed_access_today'] }}</div>
            <div style="font-size:0.8rem;color:#64748b;margin-top:0.2rem;">unauthorized door scans</div>
        </div>
    </div>

    <!-- 2-Column Section -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
        <!-- Left: Recent System Audit Activity -->
        <div style="background:#ffffff;border-radius:0.75rem;box-shadow:0 1px 3px rgba(0,0,0,0.07);border:1px solid #e2e8f0;overflow:hidden;">
            <div style="padding:1.25rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;display:flex;justify-content:space-between;align-items:center;">
                <h3 style="font-size:1rem;color:#0f172a;font-weight:700;">
                    <i class="fa-solid fa-clock-rotate-left" style="color:#0c70a2;"></i> Administrative Audit Stream (Recent 25)
                </h3>
                <a href="{{ route('access.audit.index') }}" style="font-size:0.8rem;color:#0c70a2;text-decoration:none;font-weight:600;">Filter All</a>
            </div>

            <div style="max-height:480px;overflow-y:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:0.85rem;text-align:left;">
                    <thead>
                        <tr style="background:#f1f5f9;color:#475569;font-size:0.75rem;text-transform:uppercase;">
                            <th style="padding:0.65rem 0.85rem;">Time</th>
                            <th style="padding:0.65rem 0.85rem;">Actor</th>
                            <th style="padding:0.65rem 0.85rem;">Action</th>
                            <th style="padding:0.65rem 0.85rem;">Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentAudits as $log)
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:0.65rem 0.85rem;color:#64748b;white-space:nowrap;font-size:0.8rem;">
                                    {{ $log->created_at?->timezone(config('attendance.timezone'))->format('d M, H:i') }}
                                </td>
                                <td style="padding:0.65rem 0.85rem;font-weight:600;color:#0f172a;">
                                    {{ $log->user_name }}
                                </td>
                                <td style="padding:0.65rem 0.85rem;font-weight:600;color:#0c70a2;">
                                    {{ $log->action }}
                                </td>
                                <td style="padding:0.65rem 0.85rem;color:#334155;font-size:0.8rem;">
                                    {{ Str::limit($log->description, 45) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" style="padding:2rem;text-align:center;color:#64748b;">
                                    No administrative audit logs recorded today.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Right: Recent Access Events Audit -->
        <div style="background:#ffffff;border-radius:0.75rem;box-shadow:0 1px 3px rgba(0,0,0,0.07);border:1px solid #e2e8f0;overflow:hidden;">
            <div style="padding:1.25rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;display:flex;justify-content:space-between;align-items:center;">
                <h3 style="font-size:1rem;color:#0f172a;font-weight:700;">
                    <i class="fa-solid fa-door-open" style="color:#047857;"></i> Live Door Activity &amp; Scans (Recent 20)
                </h3>
                <a href="{{ route('events.index') }}" style="font-size:0.8rem;color:#0c70a2;text-decoration:none;font-weight:600;">Events View</a>
            </div>

            <div style="max-height:480px;overflow-y:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:0.85rem;text-align:left;">
                    <thead>
                        <tr style="background:#f1f5f9;color:#475569;font-size:0.75rem;text-transform:uppercase;">
                            <th style="padding:0.65rem 0.85rem;">Time</th>
                            <th style="padding:0.65rem 0.85rem;">Personnel / Tag</th>
                            <th style="padding:0.65rem 0.85rem;">Door</th>
                            <th style="padding:0.65rem 0.85rem;">Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentAccessEvents as $evt)
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:0.65rem 0.85rem;color:#64748b;white-space:nowrap;font-size:0.8rem;">
                                    {{ $evt->occurred_at?->timezone(config('attendance.timezone'))->format('H:i:s') }}
                                </td>
                                <td style="padding:0.65rem 0.85rem;">
                                    <div style="font-weight:600;color:#0f172a;">{{ $evt->person_name ?: ($evt->employee_no ? 'Employee #' . $evt->employee_no : 'Door / Sensor Event') }}</div>
                                    <div style="font-size:0.75rem;color:#64748b;">{{ $evt->methodLabel() }}</div>
                                </td>
                                <td style="padding:0.65rem 0.85rem;color:#334155;">
                                    {{ $evt->terminal?->name ?? 'Door' }}
                                </td>
                                <td style="padding:0.65rem 0.85rem;">
                                    @php
                                        $outcome = $evt->outcomeLabel();
                                        $badgeBg = match($outcome) {
                                            'Granted' => 'background:#dcfce7;color:#047857;border:1px solid #a7f3d0;',
                                            'Denied' => 'background:#fee2e2;color:#991b1b;border:1px solid #fecaca;',
                                            default => 'background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;',
                                        };
                                    @endphp
                                    <span style="display:inline-block;padding:0.2rem 0.5rem;border-radius:4px;font-size:0.75rem;font-weight:700;{{ $badgeBg }}">
                                        {{ $outcome }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" style="padding:2rem;text-align:center;color:#64748b;">
                                    No access events recorded today.
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
