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

    // Internal notifications post to Teams through Relay, the estate's bot,
    // via the post-card ingress on commits-functions. No webhook URL, no Power
    // Automate flow and no Key Vault secret: adding Relay to a channel in Teams
    // is the whole onboarding, and the bot's credentials stay in that function
    // app rather than being handed to every caller.
    //
    // The endpoint gates on two things this config cannot arrange — the app's
    // managed identity holding the PostCard.Send app role, and its object id
    // being in POST_CARD_ALLOWED_CALLERS. An empty URL turns posting off, which
    // is what local and dev run with.
    'teams' => [
        'enabled' => filter_var(env('TEAMS_ENABLED', true), FILTER_VALIDATE_BOOL),
        'timeout' => (int) env('TEAMS_TIMEOUT', 8),
        'post_card_url' => env('TEAMS_POST_CARD_URL', ''),
        'audience' => env('TEAMS_POST_CARD_AUDIENCE', ''),
        'default_channel' => env('TEAMS_DEFAULT_CHANNEL', 'Inventory'),

        // Asset custom fields shown on checkout and check-in cards, by field
        // name => card label, in card order.
        'asset_custom_fields' => [
            'Usage' => 'Usage',
            'Catalog' => 'Catalog',
            'Area' => 'Area',
        ],

        // App Service injects these; there is no identity to borrow without
        // them, which is why local and dev post nothing rather than failing.
        // Read here rather than at the call site: env() returns null once the
        // config is cached, and a cached config is how production runs.
        'identity_endpoint' => env('IDENTITY_ENDPOINT', ''),
        'identity_header' => env('IDENTITY_HEADER', ''),
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
