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
        // Per-callback registration. On by default: an app that mounts this
        // package gets the callbacks it came for. Turn one off when the host
        // app serves that path itself. Laravel keys its route collection on
        // method+URI and the host's routes load last, so a host route at the
        // same URI shadows the package's silently — and deleting the host's
        // then activates the package's underneath, with a different response
        // shape and different auth, rather than leaving a 404.
        'agent_messages' => env('GEZEL_ROUTE_AGENT_MESSAGES', true),
        'principals_verify' => env('GEZEL_ROUTE_PRINCIPALS_VERIFY', true),
    ],
    'owner' => [
        'model' => User::class,  // the model your users log in as; must be Authenticatable, because an agent is always personal
    ],
    'auth' => [
        'driver' => env('GEZEL_AUTH_DRIVER', 'sanctum'),  // 'sanctum' | 'passport' | a ContainerBearerIssuer+PrincipalVerifier binding class-string
        'container_token_name' => env('GEZEL_CONTAINER_TOKEN_NAME', 'gezel-container'),  // the label the sanctum driver mints and requires; change only to match bearers an app already issued under another name
    ],
    'mcp' => [
        'server' => null,  // class-string<GezelMcpServer> the host app extends; null registers no route
        'path' => env('GEZEL_MCP_PATH', '/mcp'),
        'middleware' => ['auth:sanctum'],  // guards the interactive /mcp route for humans; independent of auth.driver, which governs machine container bearers
    ],
    'turn_context' => [
        'enabled' => env('GEZEL_TURN_CONTEXT_ENABLED', false),  // opt-in: registers POST {routes.prefix}/turn-context
    ],
    'usage' => [
        'enabled' => env('GEZEL_USAGE_ENABLED', true),  // gates the config push (provision hook + gezel:sync-usage-config); the callback route stays registered regardless so events never 404 into the middleware's dead-letter queue
        'monthly_token_cap' => env('GEZEL_USAGE_MONTHLY_TOKEN_CAP', 6000000),  // default cap in input+output tokens; per-owner override via the usage_token_cap column
    ],
];
