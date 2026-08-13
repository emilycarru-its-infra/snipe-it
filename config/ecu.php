<?php

return [

    'fork_source_url' => env(
        'ECU_FORK_SOURCE_URL',
        'https://github.com/emilycarru-its-infra/snipe-it',
    ),

    'build_sha' => env('ECU_BUILD_SHA', ''),

    'version_suffix' => '+ecu',

    // Categories outside the device capital plan (decision 2026-08-13,
    // AB#4473): they carry lifecycle EOL dates for operations, but the
    // refresh forecast and the multi-year horizon never surface them —
    // they are replaced ad hoc or with room projects, not on a cycle.
    'forecast_excluded_categories' => [
        'Display',
        'Printer',
        'Scanner',
        'Accessory',
    ],

];
