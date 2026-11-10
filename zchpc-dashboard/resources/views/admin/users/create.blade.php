@extends('layouts.app')

@section('title', 'Create User Login Account — ZCHPC')

@section('header', 'Create User Login Account')

@section('content')
<div style="max-width:850px;margin:0 auto;">
    <div style="margin-bottom:1rem;">
        <a href="{{ route('admin.users.index') }}" style="color:#0c70a2;text-decoration:none;font-weight:600;font-size:0.9rem;display:inline-flex;align-items:center;gap:0.4rem;">
            <i class="fa-solid fa-arrow-left"></i> Back to User Accounts
        </a>
    </div>

    @if($errors->any())
        <div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:1rem 1.25rem;border-radius:0.5rem;margin-bottom:1.5rem;">
            <strong><i class="fa-solid fa-circle-exclamation"></i> Please correct the following errors:</strong>
            <ul style="margin:0.5rem 0 0 1.25rem;">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div style="background:#ffffff;border-radius:0.75rem;box-shadow:0 1px 3px rgba(0,0,0,0.07);border:1px solid #e2e8f0;padding:1.75rem;">
        <div style="border-bottom:1px solid #e2e8f0;padding-bottom:1rem;margin-bottom:1.5rem;">
            <h2 style="font-size:1.25rem;color:#0f172a;margin-bottom:0.25rem;">New Login Account Registration</h2>
            <p style="color:#64748b;font-size:0.875rem;">
                Application login accounts provide web console access. They are distinct from physical door personnel and biometric records.
            </p>
        </div>

        <form action="{{ route('admin.users.store') }}" method="POST">
            @csrf

            <!-- Section 1: User Identity -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem;">
                <div>
                    <label style="display:block;font-weight:600;font-size:0.875rem;color:#334155;margin-bottom:0.4rem;">
                        Full Name <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="text" name="name" value="{{ old('name') }}" required placeholder="e.g. Tendai Moyo" style="width:100%;padding:0.6rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.9rem;">
                </div>

                <div>
                    <label style="display:block;font-weight:600;font-size:0.875rem;color:#334155;margin-bottom:0.4rem;">
                        Username <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="text" name="username" value="{{ old('username') }}" required placeholder="e.g. tmoyo" style="width:100%;padding:0.6rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.9rem;">
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.5rem;">
                <div>
                    <label style="display:block;font-weight:600;font-size:0.875rem;color:#334155;margin-bottom:0.4rem;">
                        Official Email Address <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="email" name="email" value="{{ old('email') }}" required placeholder="e.g. tmoyo@hpc.ac.zw" style="width:100%;padding:0.6rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.9rem;">
                </div>

                <div>
                    <label style="display:block;font-weight:600;font-size:0.875rem;color:#334155;margin-bottom:0.4rem;">
                        Office / Department / Scope
                    </label>
                    <input type="text" name="office" value="{{ old('office') }}" placeholder="e.g. Systems Engineering, Executive Directorate" style="width:100%;padding:0.6rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.9rem;">
                </div>
            </div>

            <!-- Section 2: Role Selection -->
            <div style="margin-bottom:1.5rem;padding:1.25rem;background:#f8fafc;border-radius:0.5rem;border:1px solid #e2e8f0;">
                <label style="display:block;font-weight:700;font-size:0.95rem;color:#0f172a;margin-bottom:0.5rem;">
                    Assigned Role <span style="color:#dc2626;">*</span>
                </label>
                <p style="font-size:0.825rem;color:#64748b;margin-bottom:0.85rem;">
                    Select the role governing permissions, dashboards, and authorization bounds:
                </p>

                <div style="display:grid;grid-template-columns:1fr;gap:0.6rem;">
                    @foreach($assignableRoles as $roleKey => $roleDesc)
                        <label style="display:flex;align-items:flex-start;gap:0.75rem;padding:0.75rem;background:#ffffff;border:1px solid #cbd5e1;border-radius:0.5rem;cursor:pointer;">
                            <input type="radio" name="role" value="{{ $roleKey }}" {{ old('role') === $roleKey ? 'checked' : ($roleKey === \App\Models\User::ROLE_VIEWER ? 'checked' : '') }} style="margin-top:0.25rem;">
                            <div>
                                <span style="font-weight:600;color:#0f172a;font-size:0.9rem;">{{ explode('—', $roleDesc)[0] }}</span>
                                <span style="color:#64748b;font-size:0.825rem;display:block;">— {{ explode('—', $roleDesc)[1] ?? '' }}</span>
                            </div>
                        </label>
                    @endforeach
                </div>
            </div>

            <!-- Section 3: Optional Role Permissions -->
            <div style="margin-bottom:1.5rem;">
                <label style="display:block;font-weight:700;font-size:0.9rem;color:#0f172a;margin-bottom:0.4rem;">
                    Optional Role-Specific Permissions
                </label>
                <p style="font-size:0.825rem;color:#64748b;margin-bottom:0.75rem;">
                    Super Admin and Systems Engineer automatically inherit all permissions. Use these checkboxes to grant select operational powers to management roles:
                </p>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
                    @foreach($availablePermissions as $permKey => $permLabel)
                        <label style="display:flex;align-items:center;gap:0.5rem;font-size:0.85rem;color:#334155;">
                            <input type="checkbox" name="permissions[]" value="{{ $permKey }}" {{ in_array($permKey, old('permissions', [])) ? 'checked' : '' }}>
                            <span>{{ $permLabel }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <!-- Section 4: Password & Security -->
            <div style="background:#f1f5f9;border-radius:0.5rem;padding:1.25rem;margin-bottom:1.5rem;border:1px solid #cbd5e1;">
                <label style="display:block;font-weight:700;font-size:0.9rem;color:#0f172a;margin-bottom:0.5rem;">
                    Security &amp; Password Initialization
                </label>

                <div style="margin-bottom:1rem;">
                    <label style="display:inline-flex;align-items:center;gap:0.5rem;margin-right:1.5rem;font-size:0.875rem;cursor:pointer;">
                        <input type="radio" name="password_type" value="auto" checked onchange="document.getElementById('manual-pwd-block').style.display='none';">
                        <span>Generate secure temporary password (Recommended)</span>
                    </label>
                    <label style="display:inline-flex;align-items:center;gap:0.5rem;font-size:0.875rem;cursor:pointer;">
                        <input type="radio" name="password_type" value="manual" onchange="document.getElementById('manual-pwd-block').style.display='block';">
                        <span>Set password manually</span>
                    </label>
                </div>

                <div id="manual-pwd-block" style="display:none;margin-bottom:1rem;">
                    <label style="display:block;font-weight:600;font-size:0.85rem;color:#334155;margin-bottom:0.35rem;">
                        Initial Password
                    </label>
                    <input type="password" name="password" placeholder="Minimum 8 characters" style="width:100%;max-width:350px;padding:0.55rem 0.75rem;border:1px solid #cbd5e1;border-radius:0.5rem;font-size:0.9rem;">
                </div>

                <div>
                    <label style="display:flex;align-items:center;gap:0.5rem;font-size:0.875rem;color:#334155;cursor:pointer;">
                        <input type="checkbox" name="must_change_password" value="1" checked>
                        <span>Require password change on first login</span>
                    </label>
                </div>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:0.75rem;padding-top:1rem;border-top:1px solid #e2e8f0;">
                <a href="{{ route('admin.users.index') }}" style="padding:0.6rem 1.25rem;background:#f1f5f9;color:#475569;border-radius:0.5rem;text-decoration:none;font-weight:600;font-size:0.9rem;">
                    Cancel
                </a>
                <button type="submit" style="padding:0.6rem 1.5rem;background:#0c70a2;color:#ffffff;border:none;border-radius:0.5rem;font-weight:600;font-size:0.9rem;cursor:pointer;">
                    <i class="fa-solid fa-user-check"></i> Register Account
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
