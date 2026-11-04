<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Terminal extends Model
{
    public const INTEGRATION_HIKVISION_ISAPI = 'hikvision_isapi';

    public const INTEGRATION_STANDALONE_WIEGAND = 'standalone_wiegand';

    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_ONLINE = 'online';

    public const STATUS_OFFLINE = 'offline';

    public const STATUS_CONNECTING = 'connecting';

    public const STATUS_ERROR = 'error';

    /** Whether the device itself has confirmed it can capture fingerprints. */
    public const FP_UNKNOWN = 'unknown';

    public const FP_SUPPORTED = 'supported';

    public const FP_UNSUPPORTED = 'unsupported';

    /** Preserve API management for existing terminal rows and programmatic creates. */
    protected $attributes = [
        'integration' => self::INTEGRATION_HIKVISION_ISAPI,
        'status' => self::STATUS_UNKNOWN,
    ];

    protected $fillable = [
        'name',
        'phase',
        'location',
        'ip',
        'port',
        'username',
        'password',
        'protocol',
        'timeout',
        'verify_ssl',
        'integration',
        'model',
        'status',
        'status_source',
        'last_checked_at',
        'last_seen_at',
        'last_error_at',
        'last_reconnect_at',
        'last_error',
        'last_latency_ms',
        'consecutive_failures',
        'fp_capability',
        'fp_checked_at',
        'fp_detail',
    ];

    /** @param Builder<Terminal> $query */
    public function scopeApiManaged(Builder $query): void
    {
        $query->where('integration', self::INTEGRATION_HIKVISION_ISAPI);
    }

    public function isApiManaged(): bool
    {
        return $this->integration === self::INTEGRATION_HIKVISION_ISAPI;
    }

    public function integrationLabel(): string
    {
        return match ($this->integration) {
            self::INTEGRATION_HIKVISION_ISAPI => 'Hikvision ISAPI',
            self::INTEGRATION_STANDALONE_WIEGAND => 'Standalone / Wiegand',
            default => 'Unknown integration',
        };
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        if (! $this->isApiManaged()) {
            return [];
        }

        return [
            'health',
            'door_control',
            'personnel_sync',
            'event_import',
            'fingerprint_enrollment',
            'super_fingerprint',
        ];
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    /**
     * Label shown in the device dashboard. A standalone reader is never
     * reported as offline: it has no interface this application can check.
     */
    public function statusLabel(): string
    {
        if (! $this->isApiManaged()) {
            return 'No remote API';
        }

        return match ($this->effectiveStatus()) {
            'stale' => 'Stale / not verified',
            self::STATUS_ONLINE => 'Online',
            self::STATUS_OFFLINE => 'Offline',
            self::STATUS_CONNECTING => 'Checking',
            self::STATUS_ERROR => 'Error',
            default => 'Never checked',
        };
    }

    public function statusTone(): string
    {
        if (! $this->isApiManaged()) {
            return 'muted';
        }

        return match ($this->effectiveStatus()) {
            self::STATUS_ONLINE => 'success',
            self::STATUS_OFFLINE => 'danger',
            self::STATUS_CONNECTING => 'primary',
            self::STATUS_ERROR => 'warning',
            default => 'muted',
        };
    }

    /** True only when an authenticated check has confirmed the device. */
    public function effectiveStatus(): string
    {
        if ($this->isApiManaged() && Cache::has('device-check-running:'.$this->id)) {
            return self::STATUS_CONNECTING;
        }
        if (in_array($this->status, [self::STATUS_ONLINE, self::STATUS_CONNECTING], true)
            && (! $this->last_checked_at || $this->last_checked_at->lt(now()->subSeconds(DeviceMonitorSettings::current()->staleAfterSeconds())))) {
            return 'stale';
        }

        return $this->status;
    }

    public function isOnline(): bool
    {
        return $this->isApiManaged() && $this->effectiveStatus() === self::STATUS_ONLINE;
    }

    /**
     * Why this device needs an administrator's attention, or null when it does
     * not. Standalone readers are excluded: they are deliberately unmonitored.
     */
    public function attentionReason(?DeviceMonitorSettings $settings = null): ?string
    {
        if (! $this->isApiManaged()) {
            return null;
        }

        if ($this->status === self::STATUS_ERROR) {
            return 'The device responded but its communication or authentication failed.';
        }

        if ($this->status === self::STATUS_UNKNOWN) {
            return 'This device has never been checked.';
        }

        $threshold = $this->consecutive_failures >= (int) config('devices.monitor.failures_before_alert', 3);
        if ($this->status === self::STATUS_OFFLINE && $threshold) {
            return "Unreachable for {$this->consecutive_failures} consecutive checks.";
        }

        $settings ??= DeviceMonitorSettings::current();
        if ($this->last_checked_at === null || $this->last_checked_at->lt(now()->subSeconds($settings->staleAfterSeconds()))) {
            return 'No connectivity check has completed recently.';
        }

        return null;
    }

    public function needsAttention(?DeviceMonitorSettings $settings = null): bool
    {
        return $this->attentionReason($settings) !== null;
    }

    /** @param Builder<Terminal> $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if ($term === null || $term === '') {
            return;
        }

        $like = '%'.$term.'%';
        $query->where(function (Builder $query) use ($like): void {
            $query->where('name', 'like', $like)
                ->orWhere('location', 'like', $like)
                ->orWhere('phase', 'like', $like)
                ->orWhere('ip', 'like', $like)
                ->orWhere('model', 'like', $like);
        });
    }

    protected $casts = [
        'port' => 'integer',
        'timeout' => 'integer',
        'verify_ssl' => 'boolean',
        'password' => 'encrypted',
        'last_checked_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'last_error_at' => 'datetime',
        'last_reconnect_at' => 'datetime',
        'last_latency_ms' => 'integer',
        'consecutive_failures' => 'integer',
        'fp_checked_at' => 'datetime',
    ];

    /**
     * Personnel synced to this terminal.
     */
    public function personnel(): BelongsToMany
    {
        return $this->belongsToMany(Personnel::class, 'personnel_terminals')
            ->withPivot('synced_at')
            ->withTimestamps();
    }

    /** Doors whose channel this controller enforces. */
    public function doors(): HasMany
    {
        return $this->hasMany(Door::class)->orderBy('door_no');
    }

    public function fingerprintEnrollments(): HasMany
    {
        return $this->hasMany(FingerprintEnrollment::class);
    }

    /**
     * Remote capture is offered until the device proves it cannot do it. The
     * ISAPI documentation covers the SetUp/Capture endpoints on face and
     * fingerprint terminals, but not on every access model, so "unknown" is
     * attempted and the answer is recorded instead of guessed.
     */
    public function supportsFingerprintEnrollment(): bool
    {
        return $this->isApiManaged() && $this->fp_capability !== self::FP_UNSUPPORTED;
    }

    public function fingerprintCapabilityLabel(): string
    {
        if (! $this->isApiManaged()) {
            return 'No remote API';
        }

        return match ($this->fp_capability) {
            self::FP_SUPPORTED => 'Confirmed by device',
            self::FP_UNSUPPORTED => 'Not supported',
            default => 'Not checked',
        };
    }

    public function fingerprintCapabilityTone(): string
    {
        return match ($this->fp_capability) {
            self::FP_SUPPORTED => 'success',
            self::FP_UNSUPPORTED => 'danger',
            default => 'muted',
        };
    }
}
