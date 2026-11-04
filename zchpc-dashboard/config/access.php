<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Remote fingerprint enrollment
    |--------------------------------------------------------------------------
    |
    | A capture request stays open while the person finds the scanner, so it
    | needs a much longer HTTP timeout than a normal configuration read. This
    | timeout is applied to a dedicated client for the enrollment call only;
    | the terminal's own stored timeout is left unchanged.
    |
    */
    'enrollment' => [
        'timeout_seconds' => (int) env('ACCESS_ENROLLMENT_TIMEOUT', 60),
        'queue' => env('ACCESS_ENROLLMENT_QUEUE', 'default'),
        'poll_seconds' => 2,
        'finger_ids' => range(1, 10),

        // Card-reader numbers that carry the fingerprint module. Most access
        // terminals use reader 1; change ACCESS_ENROLLMENT_READERS on models
        // where the fingerprint head is wired to another reader.
        'reader_ids' => array_values(array_filter(array_map(
            'intval',
            explode(',', (string) env('ACCESS_ENROLLMENT_READERS', '1')),
        ))) ?: [1],
    ],

    /*
    |--------------------------------------------------------------------------
    | Credential synchronization
    |--------------------------------------------------------------------------
    |
    | One queued run processes at most this many pending controller writes, so
    | a large site with several offline terminals cannot hold a worker for an
    | unbounded time. Anything left over is retried by the scheduler.
    |
    */
    'sync' => [
        'batch_limit' => (int) env('ACCESS_SYNC_BATCH_LIMIT', 25),
        'timeout_seconds' => (int) env('ACCESS_SYNC_TIMEOUT', 900),
        'queue' => env('ACCESS_SYNC_QUEUE', 'default'),
    ],

    'photos' => [
        'disk' => env('ACCESS_PHOTO_DISK', 'local'),
        'max_kilobytes' => (int) env('ACCESS_PHOTO_MAX_KB', 2048),
    ],
];
