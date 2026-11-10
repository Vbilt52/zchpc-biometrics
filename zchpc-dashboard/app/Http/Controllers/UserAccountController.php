<?php

namespace App\Http\Controllers;

use App\Models\AccessAuditLog;
use App\Models\User;
use App\Services\AccessAuditLogger;
use App\Services\DeviceActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserAccountController extends Controller
{
    public function __construct(
        private readonly AccessAuditLogger $audit,
    ) {}

    /**
     * Display a listing of user login accounts.
     */
    public function index(Request $request): View
    {
        Gate::authorize('users.manage');

        $search = trim((string) $request->query('search', ''));
        $role = (string) $request->query('role', '');
        $status = (string) $request->query('status', '');

        $query = User::query();

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('office', 'like', "%{$search}%");
            });
        }

        if ($role !== '' && array_key_exists($role, User::ROLES)) {
            $query->where('role', $role);
        }

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        $users = $query->orderBy('name')->paginate(20)->withQueryString();

        $stats = [
            'total' => User::count(),
            'active' => User::where('is_active', true)->count(),
            'inactive' => User::where('is_active', false)->count(),
            'admins' => User::whereIn('role', [User::ROLE_SUPER_ADMIN, User::ROLE_SYSTEMS_ENGINEER, User::ROLE_ADMIN])->count(),
        ];

        return view('admin.users.index', [
            'users' => $users,
            'stats' => $stats,
            'roles' => User::ROLES,
            'filters' => ['search' => $search, 'role' => $role, 'status' => $status],
        ]);
    }

    /**
     * Show form for creating a new login account.
     */
    public function create(): View
    {
        Gate::authorize('users.manage');

        return view('admin.users.create', [
            'roles' => User::ROLES,
            'assignableRoles' => [
                User::ROLE_SUPER_ADMIN => 'Super Admin — Complete system & user control',
                User::ROLE_SYSTEMS_ENGINEER => 'Systems Engineer — Full system & device access',
                User::ROLE_DIRECTOR => 'Director — Executive dashboard & management reports',
                User::ROLE_DEPUTY_DIRECTOR => 'Deputy Director — Executive dashboard & department summaries',
                User::ROLE_HR_OFFICER => 'HR Officer — Personnel onboarding & attendance management',
                User::ROLE_AUDIT_OFFICER => 'Audit Officer — Read-only audit logs & security oversight',
                User::ROLE_VIEWER => 'Viewer — General read-only access',
            ],
            'availablePermissions' => [
                'personnel.create' => 'Add Personnel (Register staff/contractors)',
                'personnel.edit' => 'Edit Personnel (Update details & departments)',
                'personnel.deactivate' => 'Deactivate Personnel (Block or suspend access)',
                'reports.executive' => 'Executive Reports (Download executive summaries)',
                'access.audit' => 'Audit Access (Inspect access logs and change history)',
            ],
        ]);
    }

    /**
     * Store a newly created login account.
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('users.manage');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'username' => ['required', 'string', 'max:50', 'alpha_dash:ascii', 'unique:users,username'],
            'email' => ['required', 'string', 'email', 'max:120', 'unique:users,email'],
            'role' => ['required', 'string', Rule::in(array_keys(User::ROLES))],
            'office' => ['nullable', 'string', 'max:100'],
            'password_type' => ['required', 'in:auto,manual'],
            'password' => ['nullable', 'required_if:password_type,manual', 'string', 'min:8'],
            'must_change_password' => ['nullable', 'boolean'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string'],
        ]);

        $plainPassword = $validated['password_type'] === 'auto'
            ? Str::password(12, true, true, false, false)
            : $validated['password'];

        $user = User::create([
            'name' => $validated['name'],
            'username' => strtolower($validated['username']),
            'email' => strtolower($validated['email']),
            'role' => $validated['role'],
            'office' => $validated['office'] ?? 'ZCHPC Operations',
            'password' => Hash::make($plainPassword),
            'is_active' => true,
            'must_change_password' => (bool) ($request->input('must_change_password', true)),
            'permissions' => $validated['permissions'] ?? [],
        ]);

        $this->audit->log(
            action: 'account_created',
            outcome: AccessAuditLog::OUTCOME_SUCCESS,
            description: "Created login account for {$user->name} ({$user->username}) with role '{$user->roleLabel()}'.",
            context: [
                'created_user_id' => $user->id,
                'created_username' => $user->username,
                'role' => $user->role,
                'office' => $user->office,
            ],
            actor: $request->user(),
            ipAddress: DeviceActivityLogger::requestIp(),
            subjectLabel: $user->name,
        );

        $flash = "User account '{$user->username}' created successfully.";
        if ($validated['password_type'] === 'auto') {
            $flash .= " Temporary password: [ {$plainPassword} ] — copy and securely issue to the user.";
        }

        return redirect()->route('admin.users.index')->with('success', $flash)->with('temp_password', $plainPassword);
    }

    /**
     * Show form for editing an existing account.
     */
    public function edit(User $user): View
    {
        Gate::authorize('users.manage');

        return view('admin.users.edit', [
            'user' => $user,
            'roles' => User::ROLES,
            'assignableRoles' => [
                User::ROLE_SUPER_ADMIN => 'Super Admin — Complete system & user control',
                User::ROLE_SYSTEMS_ENGINEER => 'Systems Engineer — Full system & device access',
                User::ROLE_DIRECTOR => 'Director — Executive dashboard & management reports',
                User::ROLE_DEPUTY_DIRECTOR => 'Deputy Director — Executive dashboard & department summaries',
                User::ROLE_HR_OFFICER => 'HR Officer — Personnel onboarding & attendance management',
                User::ROLE_AUDIT_OFFICER => 'Audit Officer — Read-only audit logs & security oversight',
                User::ROLE_VIEWER => 'Viewer — General read-only access',
            ],
            'availablePermissions' => [
                'personnel.create' => 'Add Personnel (Register staff/contractors)',
                'personnel.edit' => 'Edit Personnel (Update details & departments)',
                'personnel.deactivate' => 'Deactivate Personnel (Block or suspend access)',
                'reports.executive' => 'Executive Reports (Download executive summaries)',
                'access.audit' => 'Audit Access (Inspect access logs and change history)',
            ],
        ]);
    }

    /**
     * Update account details.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('users.manage');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'username' => ['required', 'string', 'max:50', 'alpha_dash:ascii', Rule::unique('users', 'username')->ignore($user->id)],
            'email' => ['required', 'string', 'email', 'max:120', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', 'string', Rule::in(array_keys(User::ROLES))],
            'office' => ['nullable', 'string', 'max:100'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string'],
        ]);

        // Prevent demoting the final active Super Admin
        if ($user->isSuperAdmin() && ! in_array($validated['role'], [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN], true)) {
            $remainingSuperAdmins = User::whereIn('role', [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN])
                ->where('is_active', true)
                ->where('id', '!=', $user->id)
                ->count();

            if ($remainingSuperAdmins === 0) {
                throw ValidationException::withMessages([
                    'role' => 'Cannot reassign the role of the final active Super Admin. Promote another account to Super Admin first.',
                ]);
            }
        }

        $oldRole = $user->role;
        $user->update([
            'name' => $validated['name'],
            'username' => strtolower($validated['username']),
            'email' => strtolower($validated['email']),
            'role' => $validated['role'],
            'office' => $validated['office'] ?? $user->office,
            'permissions' => $validated['permissions'] ?? [],
        ]);

        $this->audit->log(
            action: 'account_updated',
            outcome: AccessAuditLog::OUTCOME_INFO,
            description: "Updated login account for {$user->name} ({$user->username}).",
            context: [
                'target_user_id' => $user->id,
                'target_username' => $user->username,
                'old_role' => $oldRole,
                'new_role' => $user->role,
            ],
            actor: $request->user(),
            ipAddress: DeviceActivityLogger::requestIp(),
            subjectLabel: $user->name,
        );

        return redirect()->route('admin.users.index')
            ->with('success', "User account '{$user->username}' updated successfully.");
    }

    /**
     * Reset account password.
     */
    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('users.manage');

        $validated = $request->validate([
            'password_type' => ['required', 'in:auto,manual'],
            'password' => ['nullable', 'required_if:password_type,manual', 'string', 'min:8'],
            'must_change_password' => ['nullable', 'boolean'],
        ]);

        $newPassword = $validated['password_type'] === 'auto'
            ? Str::password(12, true, true, false, false)
            : $validated['password'];

        $user->update([
            'password' => Hash::make($newPassword),
            'must_change_password' => (bool) ($request->input('must_change_password', true)),
        ]);

        $this->audit->log(
            action: 'password_reset',
            outcome: AccessAuditLog::OUTCOME_WARNING,
            description: "Reset password for user '{$user->username}'.",
            context: [
                'target_user_id' => $user->id,
                'target_username' => $user->username,
                'forced_change' => $user->must_change_password,
            ],
            actor: $request->user(),
            ipAddress: DeviceActivityLogger::requestIp(),
            subjectLabel: $user->name,
        );

        return back()->with('success', "Password for '{$user->username}' has been reset.")
            ->with('temp_password', $newPassword);
    }

    /**
     * Toggle account active/deactivated status.
     */
    public function toggleStatus(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('users.manage');

        // Safeguard 1: Cannot deactivate own account
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot deactivate your own active session.');
        }

        // Safeguard 2: Cannot deactivate the final active Super Admin
        if ($user->is_active && $user->isSuperAdmin()) {
            $activeSuperAdmins = User::whereIn('role', [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN])
                ->where('is_active', true)
                ->where('id', '!=', $user->id)
                ->count();

            if ($activeSuperAdmins === 0) {
                return back()->with('error', 'Cannot deactivate the final active Super Admin account. At least one Super Admin must remain active.');
            }
        }

        $newStatus = ! $user->is_active;
        $user->update(['is_active' => $newStatus]);

        $action = $newStatus ? 'account_activated' : 'account_deactivated';
        $statusWord = $newStatus ? 'activated' : 'deactivated';

        $this->audit->log(
            action: $action,
            outcome: $newStatus ? AccessAuditLog::OUTCOME_SUCCESS : AccessAuditLog::OUTCOME_WARNING,
            description: "Account for {$user->name} ({$user->username}) was {$statusWord}.",
            context: [
                'target_user_id' => $user->id,
                'target_username' => $user->username,
                'status' => $statusWord,
            ],
            actor: $request->user(),
            ipAddress: DeviceActivityLogger::requestIp(),
            subjectLabel: $user->name,
        );

        return back()->with('success', "Account '{$user->username}' has been {$statusWord}.");
    }

    /**
     * View account login history and audit trail.
     */
    public function activity(User $user): View
    {
        Gate::authorize('users.manage');

        $performedLogs = AccessAuditLog::where('user_id', $user->id)
            ->latest('id')
            ->limit(40)
            ->get();

        $targetLogs = AccessAuditLog::where('subject_label', $user->name)
            ->orWhere('description', 'like', "%{$user->username}%")
            ->latest('id')
            ->limit(40)
            ->get();

        return view('admin.users.activity', [
            'user' => $user,
            'performedLogs' => $performedLogs,
            'targetLogs' => $targetLogs,
        ]);
    }
}
