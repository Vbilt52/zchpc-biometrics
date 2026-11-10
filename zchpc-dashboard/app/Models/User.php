<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    // Primary system roles
    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_SYSTEMS_ENGINEER = 'systems_engineer';
    public const ROLE_DIRECTOR = 'director';
    public const ROLE_DEPUTY_DIRECTOR = 'deputy_director';
    public const ROLE_HR_OFFICER = 'hr_officer';
    public const ROLE_AUDIT_OFFICER = 'audit_officer';
    public const ROLE_VIEWER = 'viewer';

    // Legacy roles (fully preserved for backward compatibility)
    public const ROLE_ADMIN = 'admin';
    public const ROLE_OPERATOR = 'operator';

    public const ROLES = [
        self::ROLE_SUPER_ADMIN => 'Super Admin',
        self::ROLE_SYSTEMS_ENGINEER => 'Systems Engineer',
        self::ROLE_DIRECTOR => 'Director',
        self::ROLE_DEPUTY_DIRECTOR => 'Deputy Director',
        self::ROLE_HR_OFFICER => 'HR Officer',
        self::ROLE_AUDIT_OFFICER => 'Audit Officer',
        self::ROLE_VIEWER => 'Viewer',
        self::ROLE_ADMIN => 'Administrator',
        self::ROLE_OPERATOR => 'Operator',
    ];

    /** Roles allowed to change devices or acknowledge device alerts. */
    public const DEVICE_OPERATOR_ROLES = [
        self::ROLE_SUPER_ADMIN,
        self::ROLE_SYSTEMS_ENGINEER,
        self::ROLE_ADMIN,
        self::ROLE_OPERATOR,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'role',
        'office',
        'is_active',
        'must_change_password',
        'last_login_at',
        'last_login_ip',
        'permissions',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
            'permissions' => 'array',
        ];
    }

    /**
     * The effective role. Accounts created before roles existed have no value
     * loaded on the model and remain administrators, matching the migration's
     * default so no existing account loses access.
     */
    public function role(): string
    {
        return $this->role ?: self::ROLE_ADMIN;
    }

    /**
     * Super Admin check. Treats both 'super_admin' and legacy 'admin' as Super Admin.
     */
    public function isSuperAdmin(): bool
    {
        return in_array($this->role(), [self::ROLE_SUPER_ADMIN, self::ROLE_ADMIN], true);
    }

    /**
     * Systems Engineer check. Explicit full-access role.
     */
    public function isSystemsEngineer(): bool
    {
        return $this->role() === self::ROLE_SYSTEMS_ENGINEER;
    }

    /**
     * Both Super Admin and Systems Engineer have full administrative control.
     */
    public function isFullAdmin(): bool
    {
        return $this->isSuperAdmin() || $this->isSystemsEngineer();
    }

    /**
     * Backward-compatible admin check: returns true for Super Admin, Systems Engineer, or Admin.
     */
    public function isAdmin(): bool
    {
        return $this->isFullAdmin();
    }

    public function isDirector(): bool
    {
        return $this->role() === self::ROLE_DIRECTOR;
    }

    public function isDeputyDirector(): bool
    {
        return $this->role() === self::ROLE_DEPUTY_DIRECTOR;
    }

    public function isExecutive(): bool
    {
        return $this->isDirector() || $this->isDeputyDirector() || $this->isFullAdmin();
    }

    public function isHrOfficer(): bool
    {
        return $this->role() === self::ROLE_HR_OFFICER || $this->isFullAdmin();
    }

    public function isAuditOfficer(): bool
    {
        return $this->role() === self::ROLE_AUDIT_OFFICER || $this->isFullAdmin();
    }

    public function isViewer(): bool
    {
        return $this->role() === self::ROLE_VIEWER;
    }

    public function canOperateDevices(): bool
    {
        return $this->isFullAdmin() || in_array($this->role(), [self::ROLE_OPERATOR], true);
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role()] ?? ucfirst($this->role());
    }

    /**
     * Determine if user has a specific permission.
     * The Systems Engineer and Super Admin have unconditional full access.
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->isFullAdmin()) {
            return true;
        }

        // Check assigned optional custom permissions array
        $custom = is_array($this->permissions) ? $this->permissions : [];
        if (in_array($permission, $custom, true)) {
            return true;
        }

        return match ($permission) {
            'dashboards.all' => $this->isFullAdmin(),
            'dashboards.executive' => $this->isExecutive(),
            'dashboards.hr' => $this->isHrOfficer(),
            'dashboards.audit' => $this->isAuditOfficer(),

            'personnel.view' => true,
            'personnel.create', 'personnel.edit', 'personnel.deactivate' => $this->isHrOfficer(),

            'access.view' => true,
            'access.manage', 'access.enroll' => $this->isFullAdmin(),

            'devices.view' => true,
            'devices.manage' => $this->isFullAdmin(),
            'devices.diagnostics' => $this->canOperateDevices(),

            'users.manage' => $this->isFullAdmin(),

            'reports.executive' => $this->isExecutive() || $this->isAuditOfficer(),
            'reports.attendance' => $this->isExecutive() || $this->isHrOfficer() || $this->isAuditOfficer(),

            'access.audit' => $this->isAuditOfficer(),

            default => false,
        };
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AccessAuditLog::class, 'user_id');
    }
}
