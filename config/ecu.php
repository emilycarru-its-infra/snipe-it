<?php

return [

    'fork_source_url' => env(
        'ECU_FORK_SOURCE_URL',
        'https://github.com/emilycarru-its-infra/snipe-it',
    ),

    'build_sha' => env('ECU_BUILD_SHA', ''),

    'version_suffix' => '+ecu',

    // Outbound asset-change announcement. Every asset create,
    // update, delete and restore posts to the Inventory automations
    // function app, which rebuilds staging/assets.csv on demand instead
    // of polling the whole hardware table every minute. Empty URL turns
    // the notifier off — dev and local never trigger a production rebuild.
    'asset_change_webhook' => [
        'url' => env('ASSET_CHANGE_WEBHOOK_URL', ''),
        'key' => env('ASSET_CHANGE_WEBHOOK_KEY', ''),
        'secret' => env('ASSET_CHANGE_WEBHOOK_SECRET', ''),
        'timeout' => (int) env('ASSET_CHANGE_WEBHOOK_TIMEOUT', 5),
    ],

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
