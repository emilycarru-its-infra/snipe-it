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

    // Teams channels for the internal notifications that used to be admin
    // email. The URLs are Power Automate ("Workflows") incoming webhooks, set
    // as app settings resolved from the commits-teams-webhooks Key Vault, so
    // no webhook URL is ever stored in the database — Settings → Emails picks
    // a channel key, and this maps the key to the URL. An empty URL turns that
    // channel off; dev and local leave them all empty on purpose.
    //
    // "default" is the fallback: with nothing configured it resolves to the
    // single endpoint the Settings → Slack form writes, which is where these
    // cards went before there was more than one channel.
    'teams' => [
        'enabled' => filter_var(env('TEAMS_WEBHOOKS_ENABLED', true), FILTER_VALIDATE_BOOL),
        'timeout' => (int) env('TEAMS_WEBHOOK_TIMEOUT', 8),
        'channels' => [
            'default' => env('TEAMS_WEBHOOK_DEFAULT', ''),
            'devices' => env('TEAMS_WEBHOOK_DEVICES', ''),
            'procurement' => env('TEAMS_WEBHOOK_PROCUREMENT', ''),
            'reports' => env('TEAMS_WEBHOOK_REPORTS', ''),
            'requests' => env('TEAMS_WEBHOOK_REQUESTS', ''),
        ],
    ],

    // Categories outside the device capital plan (decision 2026-08-13):
    // they carry lifecycle EOL dates for operations, but the
    // refresh forecast and the multi-year horizon never surface them —
    // they are replaced ad hoc or with room projects, not on a cycle.
    'forecast_excluded_categories' => [
        'Display',
        'Printer',
        'Scanner',
        'Accessory',
    ],

];
