<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceActivityLog extends Model
{
    public const UPDATED_AT = null;

    public const OUTCOME_SUCCESS = 'success';

    public const OUTCOME_FAILURE = 'failure';

    public const OUTCOME_INFO = 'info';

    protected $guarded = ['id'];

    protected $casts = [
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    public function actionLabel(): string
    {
        return match ($this->action) {
            'refresh' => 'Status refreshed',
            'refresh_all' => 'Refresh all requested',
            'refresh_offline' => 'Refresh offline requested',
            'refresh_selected' => 'Refresh selected requested',
            'refresh_completed' => 'Refresh completed',
            'refresh_cancelled' => 'Refresh cancelled',
            'reconnect' => 'Reconnection',
            'restart' => 'Device restart',
            'diagnostics' => 'Diagnostics',
            'status_change' => 'Status changed',
            'monitor_settings' => 'Monitoring settings changed',
            'alert_acknowledged' => 'Alert acknowledged',
            default => ucfirst(str_replace('_', ' ', $this->action)),
        };
    }

    public function tone(): string
    {
        return match ($this->outcome) {
            self::OUTCOME_FAILURE => 'danger',
            self::OUTCOME_SUCCESS => 'success',
            default => 'primary',
        };
    }
}
