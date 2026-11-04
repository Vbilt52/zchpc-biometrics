<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceRefreshItem extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CHECKING = 'checking';

    public const STATUS_ONLINE = 'online';

    public const STATUS_OFFLINE = 'offline';

    public const STATUS_ERROR = 'error';

    public const STATUS_CANCELLED = 'cancelled';

    protected $guarded = ['id'];

    protected $casts = [
        'latency_ms' => 'integer',
        'checked_at' => 'datetime',
    ];

    /** @return BelongsTo<DeviceRefreshRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(DeviceRefreshRun::class, 'device_refresh_run_id');
    }
}
