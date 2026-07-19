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
    'usage' => [
        'enabled' => env('GEZEL_USAGE_ENABLED', true),  // gates the config push (provision hook + gezel:sync-usage-config); the callback route stays registered regardless so events never 404 into the middleware's dead-letter queue
        'monthly_cap_usd' => env('GEZEL_USAGE_MONTHLY_CAP_USD', 20),  // default cap; per-owner override via the usage_cap_usd column
        'pricing' => [
            'version' => 1,  // bump when you change the models map; echoed back on usage callbacks for drift diagnosis
            // USD per million tokens, keyed "provider/model". The middleware
            // matches exact keys first, then the longest key that is a prefix
            // of the requested model (family match). An unknown model bills at
            // the priciest known rate and is flagged, so keep the expensive
            // models listed even if you never route to them.
            'models' => [
                'anthropic/claude-opus-4-8' => ['input_per_million' => 15.0, 'output_per_million' => 75.0, 'cache_write_per_million' => 18.75, 'cache_read_per_million' => 1.5],
                'anthropic/claude-opus-4' => ['input_per_million' => 15.0, 'output_per_million' => 75.0, 'cache_write_per_million' => 18.75, 'cache_read_per_million' => 1.5],
                'anthropic/claude-sonnet-5' => ['input_per_million' => 3.0, 'output_per_million' => 15.0, 'cache_write_per_million' => 3.75, 'cache_read_per_million' => 0.3],
                'anthropic/claude-sonnet-4' => ['input_per_million' => 3.0, 'output_per_million' => 15.0, 'cache_write_per_million' => 3.75, 'cache_read_per_million' => 0.3],
                'anthropic/claude-haiku-4-5' => ['input_per_million' => 1.0, 'output_per_million' => 5.0, 'cache_write_per_million' => 1.25, 'cache_read_per_million' => 0.1],
                'anthropic/claude-3-5-haiku' => ['input_per_million' => 0.8, 'output_per_million' => 4.0, 'cache_write_per_million' => 1.0, 'cache_read_per_million' => 0.08],
                'openai/gpt-4o' => ['input_per_million' => 2.5, 'output_per_million' => 10.0],
                'openai/gpt-4o-mini' => ['input_per_million' => 0.15, 'output_per_million' => 0.6],
                'openai/gpt-4.1' => ['input_per_million' => 2.0, 'output_per_million' => 8.0],
                'openai/gpt-4.1-mini' => ['input_per_million' => 0.4, 'output_per_million' => 1.6],
                'openai/gpt-4.1-nano' => ['input_per_million' => 0.1, 'output_per_million' => 0.4],
                'openai/text-embedding-3-small' => ['input_per_million' => 0.02, 'output_per_million' => 0.0],
            ],
        ],
    ],
];
