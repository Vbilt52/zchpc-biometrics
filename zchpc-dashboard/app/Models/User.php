<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_OPERATOR = 'operator';

    public const ROLE_VIEWER = 'viewer';

    /** Roles allowed to change devices or acknowledge device alerts. */
    public const DEVICE_OPERATOR_ROLES = [self::ROLE_ADMIN, self::ROLE_OPERATOR];

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

    public function isAdmin(): bool
    {
        return $this->role() === self::ROLE_ADMIN;
    }

    public function canOperateDevices(): bool
    {
        return in_array($this->role(), self::DEVICE_OPERATOR_ROLES, true);
    }

    public function roleLabel(): string
    {
        return ucfirst($this->role());
    }
}
