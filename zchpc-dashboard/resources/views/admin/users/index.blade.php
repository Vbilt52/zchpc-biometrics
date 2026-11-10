@extends('layouts.app')

@section('title', 'User Accounts Administration — ZCHPC')

@section('header', 'User Accounts Administration')

@section('content')
<div class="user-accounts-page">
    @if(session('success'))
        <div class="alert alert-success" style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:1rem 1.25rem;border-radius:0.5rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:0.75rem;">
            <i class="fa-solid fa-circle-check" style="font-size:1.2rem;color:#047857;"></i>
            <div>{{ session('success') }}</div>
        </div>
    @endif

    @if(session('temp_password'))
        <div class="alert alert-info" style="background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;padding:1rem 1.25rem;border-radius:0.5rem;margin-bottom:1.5rem;">
            <strong><i class="fa-solid fa-key"></i> Generated Credentials:</strong>
            <div style="margin-top:0.5rem;font-family:Consolas,monospace;font-size:1.05rem;background:#ffffff;padding:0.5rem 0.75rem;border-radius:0.25rem;display:inline-block;border:1px dashed #3b82f6;">
                {{ session('temp_password') }}
            </div>
            <p style="font-size:0.85rem;margin-top:0.4rem;color:#3b82f6;">Please copy and securely convey this password. It will not be shown again.</p>
        </div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger" style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:1rem 1.25rem;border-radius:0.5rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:0.75rem;">
            <i class="fa-solid fa-circle-exclamation" style="font-size:1.2rem;"></i>
            <div>{{ session('error') }}</div>
        </div>
    @endif

    <!-- Top KPI Row -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:1rem;margin-bottom:1.5rem;">
        <div style="background:#ffffff;border-radius:0.75rem;padding:1.25rem;box-shadow:0 1px 3px rgba(0,0,0,0.07);border:1px solid #e2e8f0;display:flex;align-items:center;gap:1rem;">
            <div style="width:48px;height:48px;border-radius:0.5rem;background:#e0f2fe;color:#0284c7;display:flex;align-items:center;justify-content:center;font-size:1.3rem;">
                <i class="fa-solid fa-users-gear"></i>
            </div>
            <div>
                <div style="font-size:0.8rem;text-transform:uppercase;color:#64748b;font-weight:600;">Total Login Accounts</div>
                <div style="font-size:1.6rem;font-weight:700;color:#0f172a;">{{ $stats['total'] }}</div>
            </div>
        </div>

        <div style="background:#ffffff;border-radius:0.75rem;padding:1.25rem;box-shadow:0 1px 3px rgba(0,0,0,0.07);border:1px solid #e2e8f0;display:flex;align-items:center;gap:1rem;">
            <div style="width:48px;height:48px;border-radius:0.5rem;background:#dcfce7;color:#047857;display:flex;align-items:center;justify-content:center;font-size:1.3rem;">
                <i class="fa-solid fa-user-check"></i>
            </div>
            <div>
                <div style="font-size:0.8rem;text-transform:uppercase;color:#64748b;font-weight:600;">Active Accounts</div>
                <div style="font-size:1.6rem;font-weight:700;color:#047857;">{{ $stats['active'] }}</div>
            </div>
        </div>

        <div style="background:#ffffff;border-radius:0.75rem;padding:1.25rem;box-shadow:0 1px 3px rgba(0,0,0,0.07);border:1px solid #e2e8f0;display:flex;align-items:center;gap:1rem;">
            <div style="width:48px;height:48px;border-radius:0.5rem;background:#fee2e2;color:#991b1b;display:flex;align-items:center;justify-content:center;font-size:1.3rem;">
                <i class="fa-solid fa-user-slash"></i>
            </div>
            <div>
                <div style="font-size:0.8rem;text-transform:uppercase;color:#64748b;font-weight:600;">Deactivated Accounts</div>
                <div style="font-size:1.6rem;font-weight:700;color:#991b1b;">{{ $stats['inactive'] }}</div>
            </div>
        </div>

        <div style="background:#ffffff;border-radius:0.75rem;padding:1.25rem;box-shadow:0 1px 3px rgba(0,0,0,0.07);border:1px solid #e2e8f0;display:flex;align-items:center;gap:1rem;">
            <div style="width:48px;height:48px;border-radius:0.5rem;background:#f3e8ff;color:#7e22ce;display:flex;align-items:center;justify-content:center;font-size:1.3rem;">
                <i class="fa-solid fa-shield-halved"></i>
            </div>
            <div>
                <div style="font-size:0.8rem;text-transform:uppercase;color:#64748b;font-weight:600;">System Admins &amp; Engineers</div>
                <div style="font-size:1.6rem;font-weight:700;color:#7e22ce;">{{ $stats['admins'] }}</div>
            </div>
        </div>
    </div>

    <!-- Filter & Action Bar -->
    <div style="background:#ffffff;border-radius:0.75rem;padding:1rem 1.25rem;box-shadow:0 1px 3px rgba(0,0,0,0.07);border:1px solid #e2e8f0;margin-bottom:1.5rem;display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:1rem;">
        <form method="GET" action="{{ route('admin.users.index') }}" style="display:flex;flex-wrap:wrap;align-items:center;gap:0.75rem;margin:0;flex:1;">
            <div style="position:relative;min-width:240px;flex:1;">
                <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:0.9rem;top:50%;transform:translateY(-50%);color:#94a3b8;"></i>
                <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Search by name, username, email, office..." style="width:100%;padding:0.55rem 0.75rem 0.55rem 2.4rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.9rem;">
            </div>

            <select name="role" style="padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.9rem;background:#ffffff;">
                <option value="">All Roles</option>
                @foreach($roles as $key => $label)
                    <option value="{{ $key }}" {{ $filters['role'] === $key ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>

            <select name="status" style="padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.9rem;background:#ffffff;">
                <option value="">All Statuses</option>
                <option value="active" {{ $filters['status'] === 'active' ? 'selected' : '' }}>Active Only</option>
                <option value="inactive" {{ $filters['status'] === 'inactive' ? 'selected' : '' }}>Deactivated Only</option>
            </select>

            <button type="submit" style="padding:0.55rem 1rem;background:#0c70a2;color:#ffffff;border:none;border-radius:0.5rem;font-weight:600;font-size:0.9rem;cursor:pointer;">
                <i class="fa-solid fa-filter"></i> Filter
            </button>

            @if($filters['search'] || $filters['role'] || $filters['status'])
                <a href="{{ route('admin.users.index') }}" style="padding:0.55rem 0.9rem;background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;border-radius:0.5rem;font-weight:600;font-size:0.9rem;text-decoration:none;">
                    Clear
                </a>
            @endif
        </form>

        <a href="{{ route('admin.users.create') }}" style="padding:0.6rem 1.25rem;background:#047857;color:#ffffff;border-radius:0.5rem;font-weight:600;font-size:0.9rem;text-decoration:none;display:inline-flex;align-items:center;gap:0.5rem;box-shadow:0 1px 2px rgba(0,0,0,0.1);">
            <i class="fa-solid fa-user-plus"></i> Create Account
        </a>
    </div>

    <!-- Accounts Table -->
    <div style="background:#ffffff;border-radius:0.75rem;box-shadow:0 1px 3px rgba(0,0,0,0.07);border:1px solid #e2e8f0;overflow:hidden;">
        <table style="width:100%;border-collapse:collapse;text-align:left;font-size:0.9rem;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;color:#475569;font-size:0.8rem;text-transform:uppercase;letter-spacing:0.04em;">
                    <th style="padding:0.85rem 1rem;">User</th>
                    <th style="padding:0.85rem 1rem;">Role</th>
                    <th style="padding:0.85rem 1rem;">Office / Scope</th>
                    <th style="padding:0.85rem 1rem;">Status</th>
                    <th style="padding:0.85rem 1rem;">Last Activity</th>
                    <th style="padding:0.85rem 1rem;text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($users as $user)
                    <tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:0.85rem 1rem;">
                            <div style="display:flex;align-items:center;gap:0.75rem;">
                                <div style="width:36px;height:36px;border-radius:50%;background:#0c70a2;color:#ffffff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.9rem;">
                                    {{ strtoupper(substr($user->name, 0, 1)) }}
                                </div>
                                <div>
                                    <div style="font-weight:600;color:#0f172a;">{{ $user->name }}</div>
                                    <div style="font-size:0.8rem;color:#64748b;">
                                        <span style="font-family:Consolas,monospace;background:#f1f5f9;padding:1px 4px;border-radius:3px;">{{ $user->username }}</span> &bull; {{ $user->email }}
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td style="padding:0.85rem 1rem;">
                            @php
                                $roleClass = match($user->role) {
                                    \App\Models\User::ROLE_SUPER_ADMIN, \App\Models\User::ROLE_ADMIN => 'background:#f3e8ff;color:#6b21a8;border:1px solid #d8b4fe;',
                                    \App\Models\User::ROLE_SYSTEMS_ENGINEER => 'background:#e0f2fe;color:#0369a1;border:1px solid #7dd3fc;',
                                    \App\Models\User::ROLE_DIRECTOR, \App\Models\User::ROLE_DEPUTY_DIRECTOR => 'background:#e0e7ff;color:#3730a3;border:1px solid #a5b4fc;',
                                    \App\Models\User::ROLE_HR_OFFICER => 'background:#dcfce7;color:#166534;border:1px solid #86efac;',
                                    \App\Models\User::ROLE_AUDIT_OFFICER => 'background:#fef3c7;color:#92400e;border:1px solid #fcd34d;',
                                    default => 'background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;',
                                };
                            @endphp
                            <span style="display:inline-block;padding:0.25rem 0.65rem;border-radius:9999px;font-weight:600;font-size:0.78rem;{{ $roleClass }}">
                                {{ $user->roleLabel() }}
                            </span>
                        </td>
                        <td style="padding:0.85rem 1rem;color:#334155;">
                            {{ $user->office ?? 'General Staff' }}
                        </td>
                        <td style="padding:0.85rem 1rem;">
                            @if($user->is_active)
                                <span style="display:inline-flex;align-items:center;gap:0.35rem;padding:0.2rem 0.6rem;background:#ecfdf5;color:#047857;border-radius:9999px;font-weight:700;font-size:0.75rem;border:1px solid #a7f3d0;">
                                    <span style="width:6px;height:6px;border-radius:50%;background:#047857;"></span> Active
                                </span>
                            @else
                                <span style="display:inline-flex;align-items:center;gap:0.35rem;padding:0.2rem 0.6rem;background:#fef2f2;color:#991b1b;border-radius:9999px;font-weight:700;font-size:0.75rem;border:1px solid #fecaca;">
                                    <span style="width:6px;height:6px;border-radius:50%;background:#991b1b;"></span> Deactivated
                                </span>
                            @endif

                            @if($user->must_change_password)
                                <span title="Temporary password assigned — user must change on login" style="margin-left:0.3rem;display:inline-block;padding:0.15rem 0.4rem;background:#fef3c7;color:#92400e;border-radius:4px;font-size:0.7rem;font-weight:600;">
                                    Pwd Change Req
                                </span>
                            @endif
                        </td>
                        <td style="padding:0.85rem 1rem;font-size:0.82rem;color:#64748b;">
                            @if($user->last_login_at)
                                <div>{{ $user->last_login_at->timezone(config('attendance.timezone'))->format('d M Y, H:i') }}</div>
                                @if($user->last_login_ip)
                                    <div style="font-family:Consolas,monospace;font-size:0.75rem;color:#94a3b8;">{{ $user->last_login_ip }}</div>
                                @endif
                            @else
                                <em>Never logged in</em>
                            @endif
                        </td>
                        <td style="padding:0.85rem 1rem;text-align:right;">
                            <div style="display:inline-flex;gap:0.4rem;align-items:center;">
                                <a href="{{ route('admin.users.edit', $user) }}" title="Edit Account" style="padding:0.4rem 0.65rem;background:#f1f5f9;color:#0c70a2;border-radius:0.35rem;text-decoration:none;font-size:0.85rem;font-weight:600;">
                                    <i class="fa-solid fa-pen-to-square"></i> Edit
                                </a>

                                <a href="{{ route('admin.users.activity', $user) }}" title="View Audit Trail" style="padding:0.4rem 0.65rem;background:#f1f5f9;color:#64748b;border-radius:0.35rem;text-decoration:none;font-size:0.85rem;">
                                    <i class="fa-solid fa-clock-rotate-left"></i>
                                </a>

                                @if($user->id !== auth()->id())
                                    <form action="{{ route('admin.users.toggle-status', $user) }}" method="POST" style="margin:0;display:inline;" onsubmit="return confirm('Are you sure you want to {{ $user->is_active ? 'deactivate' : 'activate' }} this account?');">
                                        @csrf
                                        <button type="submit" title="{{ $user->is_active ? 'Deactivate Account' : 'Activate Account' }}" style="padding:0.4rem 0.65rem;background:{{ $user->is_active ? '#fee2e2' : '#dcfce7' }};color:{{ $user->is_active ? '#991b1b' : '#047857' }};border:none;border-radius:0.35rem;font-size:0.85rem;cursor:pointer;">
                                            <i class="fa-solid {{ $user->is_active ? 'fa-ban' : 'fa-check' }}"></i>
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="padding:2.5rem;text-align:center;color:#64748b;">
                            <i class="fa-solid fa-users" style="font-size:2rem;color:#cbd5e1;margin-bottom:0.75rem;display:block;"></i>
                            No login accounts found matching the specified criteria.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if($users->hasPages())
            <div style="padding:1rem;border-top:1px solid #e2e8f0;">
                {{ $users->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
