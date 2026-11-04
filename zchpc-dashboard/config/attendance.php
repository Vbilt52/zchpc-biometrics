<?php

return [
    'timezone' => env('ATTENDANCE_TIMEZONE', 'Africa/Harare'),
    // Used only when a terminal timestamp does not contain an explicit offset.
    'device_timezone' => env('ATTENDANCE_DEVICE_TIMEZONE', 'Africa/Harare'),
    'max_events' => 20000,
    'max_pdf_rows' => 500,
];
