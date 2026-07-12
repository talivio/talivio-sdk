<?php

return [

    // The Talivio Accounts hub — talivio.com.
    'hub_url' => env('TALIVIO_HUB_URL', 'https://talivio.com'),

    // OAuth client issued from the "Talivio Ürünleri" Filament resource.
    'client_id' => env('TALIVIO_CLIENT_ID'),
    'client_secret' => env('TALIVIO_CLIENT_SECRET'),
    'redirect' => env('TALIVIO_REDIRECT_URI'), // defaults to {APP_URL}/talivio/callback

    // Ingest token for error/support telemetry (from the same Filament resource).
    'ingest_token' => env('TALIVIO_INGEST_TOKEN'),
    'telemetry_enabled' => env('TALIVIO_TELEMETRY_ENABLED', true),

    // The guard/model this app authenticates its end users with.
    'guard' => env('TALIVIO_GUARD', 'web'),
    'user_model' => env('TALIVIO_USER_MODEL', \App\Models\User::class),

    // Column on the user model storing the Talivio Accounts identity.
    'talivio_id_column' => 'talivio_id',

    // Where to send the user after a successful Talivio Accounts login.
    'login_redirect' => env('TALIVIO_LOGIN_REDIRECT', '/'),
];
