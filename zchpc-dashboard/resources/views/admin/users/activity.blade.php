@extends('layouts.app')

@section('title', 'Account Activity Trail — ZCHPC')

@section('header', 'Account Activity Trail: ' . $user->name)

@section('content')
<div style="max-width:1000px;margin:0 auto;">
    <div style="margin-bottom:1.5rem;display:flex;justify-content:space-between;align-items:center;">
        <a href="{{ route('admin.users.index') }}" style="color:#0c70a2;text-decoration:none;font-weight:600;font-size:0.9rem;display:inline-flex;align-items:center;gap:0.4rem;">
            <i class="fa-solid fa-arrow-left"></i> Back to User Accounts
        </a>

        <a href="{{ route('admin.users.edit', $user) }}" style="padding:0.45rem 1rem;background:#f1f5f9;color:#0c70a2;border-radius:0.5rem;text-decoration:none;font-weight:600;font-size:0.875rem;">
            <i class="fa-solid fa-pen-to-square"></i> Edit Account
        </a>
    </div>

    <!-- User Profile Header Summary -->
    <div style="background:#ffffff;border-radius:0.75rem;padding:1.5rem;box-shadow:0 1px 3px rgba(0,0,0,0.07);border:1px solid #e2e8f0;margin-bottom:1.5rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
        <div style="display:flex;align-items:center;gap:1rem;">
            <div style="width:54px;height:54px;border-radius:50%;background:#0c70a2;color:#ffffff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1.4rem;">
                {{ strtoupper(substr($user->name, 0, 1)) }}
            </div>
            <div>
                <h2 style="font-size:1.25rem;color:#0f172a;margin-bottom:0.2rem;">{{ $user->name }}</h2>
                <div style="font-size:0.85rem;color:#64748b;">
                    <span style="font-family:Consolas,monospace;font-weight:600;background:#f1f5f9;padding:2px 6px;border-radius:4px;">{{ $user->username }}</span>
                    &bull; {{ $user->email }}
                    &bull; {{ $user->office ?? 'General Staff' }}
                </div>
            </div>
        </div>

        <div>
            <span style="display:inline-block;padding:0.35rem 0.85rem;border-radius:9999px;font-weight:600;font-size:0.85rem;background:#e0f2fe;color:#0369a1;border:1px solid #7dd3fc;">
                {{ $user->roleLabel() }}
            </span>
        </div>
    </div>

    <!-- Section 1: Actions Performed By This User -->
    <div style="background:#ffffff;border-radius:0.75rem;box-shadow:0 1px 3px rgba(0,0,0,0.07);border:1px solid #e2e8f0;margin-bottom:1.5rem;overflow:hidden;">
        <div style="padding:1.25rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;">
            <h3 style="font-size:1rem;color:#0f172a;font-weight:700;">
                <i class="fa-solid fa-user-pen" style="color:#0c70a2;"></i> Actions Performed by {{ $user->name }} (Recent 40)
            </h3>
        </div>

        <table style="width:100%;border-collapse:collapse;font-size:0.875rem;text-align:left;">
            <thead>
                <tr style="background:#f1f5f9;color:#475569;font-size:0.78rem;text-transform:uppercase;">
                    <th style="padding:0.75rem 1rem;">Time</th>
                    <th style="padding:0.75rem 1rem;">Action</th>
                    <th style="padding:0.75rem 1rem;">Description</th>
                    <th style="padding:0.75rem 1rem;">Outcome</th>
                    <th style="padding:0.75rem 1rem;">IP Address</th>
                </tr>
            </thead>
            <tbody>
                @forelse($performedLogs as $log)
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:0.75rem 1rem;white-space:nowrap;color:#64748b;">
                            {{ $log->created_at?->timezone(config('attendance.timezone'))->format('d M Y, H:i:s') }}
                        </td>
                        <td style="padding:0.75rem 1rem;font-weight:600;color:#0f172a;">
                            {{ $log->action }}
                        </td>
                        <td style="padding:0.75rem 1rem;color:#334155;">
                            {{ $log->description ?? '—' }}
                        </td>
                        <td style="padding:0.75rem 1rem;">
                            @php
                                $badgeColor = match($log->outcome) {
                                    'success' => 'background:#dcfce7;color:#166534;',
                                    'failure' => 'background:#fee2e2;color:#991b1b;',
                                    'warning' => 'background:#fef3c7;color:#92400e;',
                                    default => 'background:#e0f2fe;color:#0369a1;',
                                };
                            @endphp
                            <span style="display:inline-block;padding:0.2rem 0.5rem;border-radius:4px;font-size:0.75rem;font-weight:600;{{ $badgeColor }}">
                                {{ ucfirst($log->outcome) }}
                            </span>
                        </td>
                        <td style="padding:0.75rem 1rem;font-family:Consolas,monospace;font-size:0.8rem;color:#64748b;">
                            {{ $log->ip_address ?? '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" style="padding:2rem;text-align:center;color:#64748b;">
                            No actions recorded for this user yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Section 2: Administrative History Targeting This Account -->
    <div style="background:#ffffff;border-radius:0.75rem;box-shadow:0 1px 3px rgba(0,0,0,0.07);border:1px solid #e2e8f0;overflow:hidden;">
        <div style="padding:1.25rem;border-bottom:1px solid #e2e8f0;background:#f8fafc;">
            <h3 style="font-size:1rem;color:#0f172a;font-weight:700;">
                <i class="fa-solid fa-shield-halved" style="color:#7e22ce;"></i> Administrative Changes to this Account
            </h3>
        </div>

        <table style="width:100%;border-collapse:collapse;font-size:0.875rem;text-align:left;">
            <thead>
                <tr style="background:#f1f5f9;color:#475569;font-size:0.78rem;text-transform:uppercase;">
                    <th style="padding:0.75rem 1rem;">Time</th>
                    <th style="padding:0.75rem 1rem;">Admin / Actor</th>
                    <th style="padding:0.75rem 1rem;">Action</th>
                    <th style="padding:0.75rem 1rem;">Description</th>
                    <th style="padding:0.75rem 1rem;">IP Address</th>
                </tr>
            </thead>
            <tbody>
                @forelse($targetLogs as $log)
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:0.75rem 1rem;white-space:nowrap;color:#64748b;">
                            {{ $log->created_at?->timezone(config('attendance.timezone'))->format('d M Y, H:i:s') }}
                        </td>
                        <td style="padding:0.75rem 1rem;font-weight:600;color:#0f172a;">
                            {{ $log->user_name }}
                        </td>
                        <td style="padding:0.75rem 1rem;color:#0f172a;font-weight:600;">
                            {{ $log->action }}
                        </td>
                        <td style="padding:0.75rem 1rem;color:#334155;">
                            {{ $log->description ?? '—' }}
                        </td>
                        <td style="padding:0.75rem 1rem;font-family:Consolas,monospace;font-size:0.8rem;color:#64748b;">
                            {{ $log->ip_address ?? '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" style="padding:2rem;text-align:center;color:#64748b;">
                            No administrative history logs found for this account.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
