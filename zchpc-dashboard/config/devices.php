<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Background monitoring
    |--------------------------------------------------------------------------
    |
    | The scheduler runs `terminals:ping` every minute. A terminal is only
    | contacted again when this interval has elapsed, so raising it reduces
    | radio/network load on the terminals without changing the schedule.
    | Administrators can change the interval from the device dashboard; these
    | values are the defaults and the allowed range.
    |
    */
    'monitor' => [
        'interval_seconds' => (int) env('DEVICE_MONITOR_INTERVAL', 60),
        'min_interval_seconds' => 15,
        'max_interval_seconds' => 3600,
        'presets' => [30, 60, 300, 600],

        // A device is "requiring attention" when it has not been checked for
        // this many intervals (covers a stopped scheduler or worker).
        'stale_after_intervals' => 3,

        // Background checks retry transient failures only (unreachable, busy).
        'retry_attempts' => (int) env('DEVICE_MONITOR_RETRIES', 2),
        'retry_backoff_ms' => (int) env('DEVICE_MONITOR_BACKOFF_MS', 400),
        'failures_before_alert' => (int) env('DEVICE_MONITOR_FAILURE_THRESHOLD', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reachability and refresh
    |--------------------------------------------------------------------------
    |
    | Online means an authenticated ISAPI read succeeded. The TCP reachability
    | probe only shortens diagnostics and bulk refreshes; it never marks a
    | device online on its own. Bulk checks probe at most `concurrency`
    | devices' ports at once, then authenticate each reachable device in turn
    | so no terminal receives simultaneous configuration reads.
    |
    */
    'reachability_timeout' => (float) env('DEVICE_REACHABILITY_TIMEOUT', 2.0),
    'refresh_concurrency' => (int) env('DEVICE_REFRESH_CONCURRENCY', 4),
    'refresh_queue' => env('DEVICE_REFRESH_QUEUE', 'default'),
    'refresh_inter_device_delay_ms' => (int) env('DEVICE_REFRESH_DELAY_MS', 100),

    // Upper bound for a background refresh run, so a hung device cannot block
    // the queue worker indefinitely.
    'refresh_timeout_seconds' => (int) env('DEVICE_REFRESH_TIMEOUT', 900),
];
