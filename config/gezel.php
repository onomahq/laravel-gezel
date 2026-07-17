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
    'timeout' => env('GEZEL_TIMEOUT', 120),        // request/response calls; a chat turn uses 'stream' below
    'lock_store' => env('GEZEL_LOCK_STORE'),  // cache store backing the bearer-rotation lock; null uses the default. Must share state across processes (redis, memcached, database) or the lock is decoration.
    'stream' => [
        'connect_timeout' => env('GEZEL_STREAM_CONNECT_TIMEOUT', 10),
        'idle_timeout' => env('GEZEL_STREAM_IDLE_TIMEOUT', 120),   // abort after this long with nothing arriving
        'max_duration' => env('GEZEL_STREAM_MAX_DURATION', 600),   // runaway backstop; also the stop flag's TTL
    ],
    'provisioning' => [
        'enabled' => env('GEZEL_PROVISIONING_ENABLED', true),
        'strategy' => 'opt-in',   // 'observer' (signup auto) | 'opt-in' (UI action) | 'manual'
        'self_heal' => env('GEZEL_PROVISIONING_SELF_HEAL', false),  // hourly gezel:provision-missing
    ],
    'routes' => [
        'prefix' => 'api/v1/internal',
        'middleware' => ['api'],
    ],
    'owner' => [
        'model' => User::class,  // any Eloquent model: User, Team, ...
        'acknowledges_shared_memory' => env('GEZEL_OWNER_ACKNOWLEDGES_SHARED_MEMORY', false),  // required true when owner.model cannot authenticate
    ],
    'auth' => [
        'driver' => env('GEZEL_AUTH_DRIVER', 'sanctum'),  // 'sanctum' | 'passport' | a ContainerBearerIssuer+PrincipalVerifier binding class-string
    ],
    'mcp' => [
        'server' => null,  // class-string<GezelMcpServer> the host app extends; null registers no route
        'path' => env('GEZEL_MCP_PATH', '/mcp'),
        'middleware' => ['auth:sanctum'],  // matches the 'sanctum' auth.driver default above; change together if you switch drivers
    ],
    'turn_context' => [
        'enabled' => env('GEZEL_TURN_CONTEXT_ENABLED', false),  // opt-in: registers POST {routes.prefix}/turn-context
    ],
];
