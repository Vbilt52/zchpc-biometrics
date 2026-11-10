<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceMonitorSettings extends Model
{
    /** Only one row is used; it holds the site-wide monitoring configuration. */
    protected $guarded = ['id'];

    protected $casts = [
        'enabled' => 'boolean',
        'interval_seconds' => 'integer',
        'alert_cooldown_minutes' => 'integer',
        'last_run_at' => 'datetime',
    ];

    /**
     * The singleton settings row, memoised for the current request so a
     * dashboard render does not query it once per device.
     */
    public static function current(): self
    {
        if (app()->bound(self::class)) {
            return app(self::class);
        }

        $settings = self::query()->first() ?? self::query()->create([
            'enabled' => true,
            'interval_seconds' => (int) config('devices.monitor.interval_seconds', 60),
            'alert_cooldown_minutes' => 15,
        ]);

        app()->instance(self::class, $settings);

        return $settings;
    }

    /** Seconds without a successful check after which a device is stale. */
    public function staleAfterSeconds(): int
    {
        return max(900, $this->interval_seconds * (int) config('devices.monitor.stale_after_intervals', 10));
    }
}
