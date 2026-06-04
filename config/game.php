<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Laravel Cloud API
    |--------------------------------------------------------------------------
    |
    | The API token is created in the Laravel Cloud dashboard under your
    | organization settings. It is used to poll the status of each target
    | environment. Status checks are disabled by default because the API
    | currently reports hibernating environments as "running", so the calls
    | only add latency. Flip the flag once the API reports real statuses.
    |
    */

    'cloud_status_enabled' => env('GAME_CLOUD_STATUS_ENABLED', false),

    'cloud_api_token' => env('LARAVEL_CLOUD_API_TOKEN'),

    'cloud_base_url' => env('LARAVEL_CLOUD_BASE_URL', 'https://cloud.laravel.com'),

    /*
    |--------------------------------------------------------------------------
    | Game Tuning
    |--------------------------------------------------------------------------
    |
    | Statuses are cached for a few seconds so the UI may poll every five
    | seconds without hammering the Cloud API. A round that never finishes
    | loading is voided after the timeout below.
    |
    */

    'status_cache_ttl' => 8,

    'round_timeout_ms' => 30000,

    'server_region' => env('GAME_SERVER_REGION', 'London'),

    /*
    |--------------------------------------------------------------------------
    | Wake Cooldown
    |--------------------------------------------------------------------------
    |
    | The Cloud API does not reliably report scale-to-zero sleep, so the game
    | tracks wake-ups itself. A target only becomes playable again once this
    | many seconds have passed since it was last clicked, giving the platform
    | time to put it back to sleep.
    |
    */

    'wake_cooldown' => env('GAME_WAKE_COOLDOWN', 90),

    /*
    |--------------------------------------------------------------------------
    | Target Applications
    |--------------------------------------------------------------------------
    |
    | The hibernating Laravel Cloud applications players race against. Each
    | target needs its public URL plus the application and environment IDs
    | from the Laravel Cloud API.
    |
    */

    'targets' => [
        [
            'name' => 'Laravel Live UK 1',
            'url' => 'https://laravelliveuk-1-main-iphqld.laravel.cloud',
            'application_id' => 'app-a1f1b87e-5af0-4b75-acfe-29d94a1eb2e2',
            'environment_id' => 'env-a1f1b87e-6ea7-46d3-ab6c-dae952ae5465',
        ],
        [
            'name' => 'Laravel Live UK 2',
            'url' => 'https://laravelliveuk-2-main-tnhomh.laravel.cloud',
            'application_id' => 'app-a1f1b953-32a9-4020-9180-980cac0bf028',
            'environment_id' => 'env-a1f1b953-48cb-4dda-9866-326534b460df',
        ],
        [
            'name' => 'Laravel Live UK 3',
            'url' => 'https://laravelliveuk-3-main-hlhkqy.laravel.cloud',
            'application_id' => 'app-a1f1ba81-2fdf-416b-8b12-c1806cc76dc7',
            'environment_id' => 'env-a1f1ba81-4943-449c-bd2c-6aa521989ad7',
        ],
        [
            'name' => 'Laravel Live UK 4',
            'url' => 'https://laravelliveuk-4-main-cdqava.laravel.cloud',
            'application_id' => 'app-a1f1bbf4-2396-4588-b647-a40494c6eafa',
            'environment_id' => 'env-a1f1bbf4-3936-4b76-834d-ecb77f3bdc08',
        ],
        [
            'name' => 'Laravel Live UK 5',
            'url' => 'https://laravelliveuk-5-main-2jnctj.laravel.cloud',
            'application_id' => 'app-a1f1bcd3-0d6a-4558-9a5e-a00ec63597e1',
            'environment_id' => 'env-a1f1bcd3-24d3-4297-85ce-0b1a2847fa71',
        ],
    ],

];
