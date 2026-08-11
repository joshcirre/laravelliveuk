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
    | currently reports environments that scaled to zero as "running", so
    | the calls only add latency. Flip the flag once the API reports real
    | statuses.
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

    'server_region' => env('GAME_SERVER_REGION', 'Frankfurt'),

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
    | The scale-to-zero Laravel Cloud applications players race against. Each
    | target needs its public URL plus the application and environment IDs
    | from the Laravel Cloud API.
    |
    */

    'targets' => [
        [
            'name' => 'Laravel Live DK 1',
            'url' => 'https://laravellivedk-1-production-vrjeop.laravel.cloud',
            'application_id' => 'app-a27a56ed-5eca-49e5-92d3-085d25f18445',
            'environment_id' => 'env-a27a56ef-a3ec-47cb-bfe1-695132f8a57b',
        ],
        [
            'name' => 'Laravel Live DK 2',
            'url' => 'https://laravellivedk-2-production-mf18nf.laravel.cloud',
            'application_id' => 'app-a27a5714-cb51-402d-bd66-695292b498eb',
            'environment_id' => 'env-a27a5716-fc57-4a9b-bbbf-a21fe2ae37b7',
        ],
        [
            'name' => 'Laravel Live DK 3',
            'url' => 'https://laravellivedk-3-production-s6dbyj.laravel.cloud',
            'application_id' => 'app-a27a573c-0c06-4c73-89e1-f78a179d5c04',
            'environment_id' => 'env-a27a573d-e6ac-4baf-ba79-d47649c0c4f9',
        ],
        [
            'name' => 'Laravel Live DK 4',
            'url' => 'https://laravellivedk-4-production-i4vbvs.laravel.cloud',
            'application_id' => 'app-a27a5793-6401-413e-9217-cb9c0a0ff954',
            'environment_id' => 'env-a27a5795-2afa-48e6-b56a-c2b90b441c05',
        ],
        [
            'name' => 'Laravel Live DK 5',
            'url' => 'https://laravellivedk-5-production-vu4gqx.laravel.cloud',
            'application_id' => 'app-a27a57b3-f77d-4935-b90a-f14194c96fad',
            'environment_id' => 'env-a27a57b5-f565-474a-8f47-6dfa260c0e8f',
        ],
    ],

];
