<?php

use App\Models\User;

// config for Onomahq/Gezel
return [
    'app_id' => env('GEZEL_APP_ID'),                    // this app's [[apps]].id; asserted by gezel:health
    'middleware' => [
        'url' => env('GEZEL_MIDDLEWARE_URL', 'http://localhost:8800'),
        'app_token' => env('GEZEL_APP_TOKEN'),          // app → middleware ([[apps]].auth_token)
        'service_token' => env('GEZEL_SERVICE_TOKEN'),  // middleware → app ([apps.application].token)
    ],
    'timeout' => env('GEZEL_TIMEOUT', 120),
    'provisioning' => [
        'enabled' => env('GEZEL_PROVISIONING_ENABLED', true),
        'strategy' => 'opt-in',   // 'observer' (signup auto) | 'opt-in' (UI action) | 'manual'
    ],
    'routes' => [
        'prefix' => 'api/v1/internal',
        'middleware' => ['api'],
    ],
    'owner' => [
        'model' => User::class,  // any Eloquent model: User, Team, ...
        'acknowledges_shared_memory' => env('GEZEL_OWNER_ACKNOWLEDGES_SHARED_MEMORY', false),  // required true for non-User owner.model — see Module 2
    ],
    'auth' => [
        'driver' => env('GEZEL_AUTH_DRIVER', 'sanctum'),  // 'sanctum' | 'passport' | a ContainerBearerIssuer+PrincipalVerifier binding class-string
    ],
];
