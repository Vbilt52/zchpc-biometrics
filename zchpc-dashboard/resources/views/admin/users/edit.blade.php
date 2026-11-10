@extends('layouts.app')

@section('title', 'Edit User Account — ZCHPC')

@section('header', 'Edit User Account')

@section('content')
<div style="max-width:850px;margin:0 auto;">
    <div style="margin-bottom:1rem;display:flex;justify-content:space-between;align-items:center;">
        <a href="{{ route('admin.users.index') }}" style="color:#0c70a2;text-decoration:none;font-weight:600;font-size:0.9rem;display:inline-flex;align-items:center;gap:0.4rem;">
            <i class="fa-solid fa-arrow-left"></i> Back to User Accounts
        </a>

        <a href="{{ route('admin.users.activity', $user) }}" style="color:#64748b;text-decoration:none;font-weight:600;font-size:0.9rem;display:inline-flex;align-items:center;gap:0.4rem;">
            <i class="fa-solid fa-clock-rotate-left"></i> View Activity Trail
        </a>
    </div>

    @if(session('success'))
        <div style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:1rem 1.25rem;border-radius:0.5rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:0.75rem;">
            <i class="fa-solid fa-circle-check" style="font-size:1.2rem;color:#047857;"></i>
            <div>{{ session('success') }}</div>
        </div>
    @endif

    @if(session('temp_password'))
        <div style="background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;padding:1rem 1.25rem;border-radius:0.5rem;margin-bottom:1.5rem;">
            <strong><i class="fa-solid fa-key"></i> New Password Generated:</strong>
            <div style="margin-top:0.5rem;font-family:Consolas,monospace;font-size:1.05rem;background:#ffffff;padding:0.5rem 0.75rem;border-radius:0.25rem;display:inline-block;border:1px dashed #3b82f6;">
                {{ session('temp_password') }}
            </div>
            <p style="font-size:0.85rem;margin-top:0.4rem;color:#3b82f6;">Please securely issue this password to the account holder.</p>
        </div>
    @endif

    @if($errors->any())
        <div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:1rem 1.25rem;border-radius:0.5rem;margin-bottom:1.5rem;">
            <strong><i class="fa-solid fa-circle-exclamation"></i> Errors encountered:</strong>
            <ul style="margin:0.5rem 0 0 1.25rem;">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Main Account Edit Card -->
    <div style="background:#ffffff;border-radius:0.75rem;box-shadow:0 1px 3px rgba(0,0,0,0.07);border:1px solid #e2e8f0;padding:1.75rem;margin-bottom:1.5rem;">
        <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #e2e8f0;padding-bottom:1rem;margin-bottom:1.5rem;">
            <div>
                <h2 style="font-size:1.25rem;color:#0f172a;margin-bottom:0.25rem;">Account: {{ $user->name }}</h2>
                <div style="font-size:0.85rem;color:#64748b;">
                    Username: <span style="font-family:Consolas,monospace;font-weight:600;">{{ $user->username }}</span>
                    &bull; Registered: {{ $user->created_at?->format('d M Y') ?? 'System default' }}
                </div>
            </div>

            <div>
                @if($user->is_active)
                    <span style="display:inline-flex;align-items:center;gap:0.35rem;padding:0.25rem 0.75rem;background:#ecfdf5;color:#047857;border-radius:9999px;font-weight:700;font-size:0.8rem;border:1px solid #a7f3d0;">
                        <span style="width:7px;height:7px;border-radius:50%;background:#047857;"></span> Active
                    </span>
                @else
                    <span style="display:inline-flex;align-items:center;gap:0.35rem;padding:0.25rem 0.75rem;background:#fef2f2;color:#991b1b;border-radius:9999px;font-weight:700;font-size:0.8rem;border:1px solid #fecaca;">
                        <span style="width:7px;height:7px;border-radius:50%;background:#991b1b;"></span> Deactivated
                    </span>
                @endif
            </div>
        </div>

        <form action="{{ route('admin.users.update', $user) }}" method="POST">
            @csrf
            @method('PUT')

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem;">
                <div>
                    <label style="display:block;font-weight:600;font-size:0.875rem;color:#334155;margin-bottom:0.4rem;">
                        Full Name <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="text" name="name" value="{{ old('name', $user->name) }}" required style="width:100%;padding:0.6rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.9rem;">
                </div>

                <div>
                    <label style="display:block;font-weight:600;font-size:0.875rem;color:#334155;margin-bottom:0.4rem;">
                        Username <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="text" name="username" value="{{ old('username', $user->username) }}" required style="width:100%;padding:0.6rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.9rem;">
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.5rem;">
                <div>
                    <label style="display:block;font-weight:600;font-size:0.875rem;color:#334155;margin-bottom:0.4rem;">
                        Email Address <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="email" name="email" value="{{ old('email', $user->email) }}" required style="width:100%;padding:0.6rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.9rem;">
                </div>

                <div>
                    <label style="display:block;font-weight:600;font-size:0.875rem;color:#334155;margin-bottom:0.4rem;">
                        Office / Scope
                    </label>
                    <input type="text" name="office" value="{{ old('office', $user->office) }}" placeholder="e.g. Systems Engineering" style="width:100%;padding:0.6rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.9rem;">
                </div>
            </div>

            <!-- Role Selection -->
            <div style="margin-bottom:1.5rem;padding:1.25rem;background:#f8fafc;border-radius:0.5rem;border:1px solid #e2e8f0;">
                <label style="display:block;font-weight:700;font-size:0.95rem;color:#0f172a;margin-bottom:0.5rem;">
                    Role &amp; Privilege Level <span style="color:#dc2626;">*</span>
                </label>

                <div style="display:grid;grid-template-columns:1fr;gap:0.6rem;">
                    @foreach($assignableRoles as $roleKey => $roleDesc)
                        <label style="display:flex;align-items:flex-start;gap:0.75rem;padding:0.75rem;background:#ffffff;border:1px solid #cbd5e1;border-radius:0.5rem;cursor:pointer;">
                            <input type="radio" name="role" value="{{ $roleKey }}" {{ old('role', $user->role) === $roleKey ? 'checked' : '' }} style="margin-top:0.25rem;">
                            <div>
                                <span style="font-weight:600;color:#0f172a;font-size:0.9rem;">{{ explode('—', $roleDesc)[0] }}</span>
                                <span style="color:#64748b;font-size:0.825rem;display:block;">— {{ explode('—', $roleDesc)[1] ?? '' }}</span>
                            </div>
                        </label>
                    @endforeach
                </div>
            </div>

            <!-- Optional Permissions -->
            <div style="margin-bottom:1.5rem;">
                <label style="display:block;font-weight:700;font-size:0.9rem;color:#0f172a;margin-bottom:0.4rem;">
                    Optional Role-Specific Permissions
                </label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
                    @php
                        $userPerms = is_array($user->permissions) ? $user->permissions : [];
                    @endphp
                    @foreach($availablePermissions as $permKey => $permLabel)
                        <label style="display:flex;align-items:center;gap:0.5rem;font-size:0.85rem;color:#334155;">
                            <input type="checkbox" name="permissions[]" value="{{ $permKey }}" {{ in_array($permKey, old('permissions', $userPerms)) ? 'checked' : '' }}>
                            <span>{{ $permLabel }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:0.75rem;padding-top:1rem;border-top:1px solid #e2e8f0;">
                <button type="submit" style="padding:0.6rem 1.5rem;background:#0c70a2;color:#ffffff;border:none;border-radius:0.5rem;font-weight:600;font-size:0.9rem;cursor:pointer;">
                    <i class="fa-solid fa-floppy-disk"></i> Save Account Changes
                </button>
            </div>
        </form>
    </div>

    <!-- Password Reset Card -->
    <div style="background:#ffffff;border-radius:0.75rem;box-shadow:0 1px 3px rgba(0,0,0,0.07);border:1px solid #e2e8f0;padding:1.75rem;margin-bottom:1.5rem;">
        <h3 style="font-size:1.1rem;color:#0f172a;margin-bottom:0.35rem;"><i class="fa-solid fa-key" style="color:#d97706;"></i> Reset User Password</h3>
        <p style="font-size:0.85rem;color:#64748b;margin-bottom:1.25rem;">
            Issue a replacement password for this user. The temporary password will be displayed immediately upon generation.
        </p>

        <form action="{{ route('admin.users.reset-password', $user) }}" method="POST">
            @csrf
            <div style="margin-bottom:1rem;">
                <label style="display:inline-flex;align-items:center;gap:0.5rem;margin-right:1.5rem;font-size:0.875rem;cursor:pointer;">
                    <input type="radio" name="password_type" value="auto" checked onchange="document.getElementById('edit-manual-pwd').style.display='none';">
                    <span>Generate secure temporary password</span>
                </label>
                <label style="display:inline-flex;align-items:center;gap:0.5rem;font-size:0.875rem;cursor:pointer;">
                    <input type="radio" name="password_type" value="manual" onchange="document.getElementById('edit-manual-pwd').style.display='block';">
                    <span>Specify password manually</span>
                </label>
            </div>

            <div id="edit-manual-pwd" style="display:none;margin-bottom:1rem;">
                <input type="password" name="password" placeholder="Enter new password (min 8 chars)" style="width:100%;max-width:350px;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.9rem;">
            </div>

            <div style="margin-bottom:1.25rem;">
                <label style="display:flex;align-items:center;gap:0.5rem;font-size:0.875rem;color:#334155;cursor:pointer;">
                    <input type="checkbox" name="must_change_password" value="1" checked>
                    <span>Require user to change password on their next sign-in</span>
                </label>
            </div>

            <button type="submit" onclick="return confirm('Confirm resetting password for {{ $user->username }}?');" style="padding:0.55rem 1.25rem;background:#d97706;color:#ffffff;border:none;border-radius:0.5rem;font-weight:600;font-size:0.875rem;cursor:pointer;">
                <i class="fa-solid fa-rotate-left"></i> Reset Password
            </button>
        </form>
    </div>

    <!-- Account Status (Activate/Deactivate) -->
    @if($user->id !== auth()->id())
        <div style="background:#ffffff;border-radius:0.75rem;box-shadow:0 1px 3px rgba(0,0,0,0.07);border:1px solid #e2e8f0;padding:1.75rem;">
            <h3 style="font-size:1.1rem;color:#0f172a;margin-bottom:0.35rem;">
                <i class="fa-solid {{ $user->is_active ? 'fa-user-slash' : 'fa-user-check' }}" style="color:{{ $user->is_active ? '#dc2626' : '#047857' }};"></i>
                {{ $user->is_active ? 'Deactivate Account' : 'Reactivate Account' }}
            </h3>
            <p style="font-size:0.85rem;color:#64748b;margin-bottom:1.25rem;">
                {{ $user->is_active
                    ? 'Deactivating this account prevents the user from signing in. All audit records, past actions, and references are preserved.'
                    : 'Reactivating this account restores the user’s ability to sign in with their existing credentials.' }}
            </p>

            <form action="{{ route('admin.users.toggle-status', $user) }}" method="POST">
                @csrf
                <button type="submit" onclick="return confirm('Are you sure you want to {{ $user->is_active ? 'deactivate' : 'activate' }} account {{ $user->username }}?');" style="padding:0.55rem 1.25rem;background:{{ $user->is_active ? '#dc2626' : '#047857' }};color:#ffffff;border:none;border-radius:0.5rem;font-weight:600;font-size:0.875rem;cursor:pointer;">
                    {{ $user->is_active ? 'Deactivate This Account' : 'Reactivate This Account' }}
                </button>
            </form>
        </div>
    @endif
</div>
@endsection
