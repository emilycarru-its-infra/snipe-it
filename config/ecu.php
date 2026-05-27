<?php

return [

    'fork_source_url' => env(
        'ECU_FORK_SOURCE_URL',
        'https://github.com/emilycarru-its-infra/snipe-it',
    ),

    'build_sha' => env('ECU_BUILD_SHA', ''),

    'version_suffix' => '+ecu',

];
