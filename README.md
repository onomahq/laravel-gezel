# laravel-gezel

[![GitHub Tests Action Status](https://github.com/onomahq/laravel-gezel/actions/workflows/run-tests.yml/badge.svg)](https://github.com/onomahq/laravel-gezel/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://github.com/onomahq/laravel-gezel/actions/workflows/fix-php-code-style-issues.yml/badge.svg)](https://github.com/onomahq/laravel-gezel/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)

Shared Laravel package for apps built on Gezel, the per-user agent runtime behind the middleware gateway. Used by Onoma Platform, Stagent and Calmunity.

Spec: `research/26-07-16-laravel-gezel-package.md` in [onomahq/onoma](https://github.com/onomahq/onoma).

## What it ships

- `config/gezel.php` — one canonical config (middleware URL, app token, service token; see [Tokens](#tokens))
- Migration stub + `HasGezelAgent` trait on a configurable owner model (default `User`; must be `Authenticatable` — an agent is always personal)
- Clients: `GezelOrchestrator` (container lifecycle), `GezelClient` (per-owner proxy), `GezelStreamClient` (SSE chat)
- Inbound callback routes: agent-messages, principals/verify, turn-context — service-token guarded
- Pluggable auth seams: `ContainerBearerIssuer`, `PrincipalVerifier` (Sanctum and Passport drivers)
- Provisioning job + artisan commands (`gezel:provision-missing`, `gezel:reconcile-container-bearers`, `gezel:health`)
- Abstract `GezelMcpServer` on `laravel/mcp` + `TurnContextProvider` seam

## Installation

```bash
composer require onomahq/laravel-gezel
php artisan vendor:publish --tag="laravel-gezel-config"
php artisan vendor:publish --tag="laravel-gezel-migrations"
php artisan migrate
```

## Tokens

Three secrets, two directions. Set all three explicitly in every app.

| Env var | Direction | `middleware.toml` counterpart |
|---|---|---|
| `GEZEL_APP_ID` | identifies this app | `[[apps]].id` |
| `GEZEL_APP_TOKEN` | app → middleware | `[[apps]].auth_token` |
| `GEZEL_SERVICE_TOKEN` | middleware → app | `[apps.application].token`, or the top-level `[application].token` an app that declares no override inherits |

`GEZEL_APP_TOKEN` is what this app presents when it calls the middleware, and it is how the
middleware decides which app is calling. `GEZEL_SERVICE_TOKEN` is what the middleware presents
when it calls back into this app, guarding every inbound callback route. They are separate
secrets with separate blast radii. Rotate them independently.

**Set each one explicitly.** Do not chain a fallback to a legacy name in your published
`config/gezel.php`. A fallback makes "configured correctly" and "never configured"
indistinguishable, and the failure surfaces as a 401 from the middleware at deploy time with no
other signal.

**One name per secret.** If a value is reachable through two config keys, the two eventually
disagree. Point the second key at the first rather than reading the environment twice.

**Migrating an app that already has its own names.** Set the canonical variable to the same value
and deploy. Then repoint the config and delete the legacy variable. Carry a fallback for one
deploy, never into a release.

**Name the direction, not the peer.** A variable called `..._MIDDLEWARE_...` reads like the
middleware's own credential whichever way the traffic flows, so it will be wired backwards
eventually. The three names above say who calls whom.

Verify before you trust it:

```bash
php artisan gezel:health
```

## Callback routes

The middleware calls back into this app under `gezel.routes.prefix`. `agent-messages` and
`principals/verify` register by default and can be turned off per route
(`gezel.routes.agent_messages`, `gezel.routes.principals_verify`) by an app that serves those
paths itself. `usage` always registers: the middleware POSTs its ledger to a hardcoded path and
dead-letters permanently on a 404.

`turn-context` takes two things: `gezel.turn_context.enabled` and a `TurnContextProvider` bound by
the app. The flag alone leaves the package's null default in place, which composes nothing for
every relayed turn while the endpoint keeps answering 200 — grounding is optional by contract, so
the middleware relays anyway and the miswiring never surfaces. Without a binding there is no
route, and the middleware gets a 404 it already handles.

Bind it in a service provider's `register()`. Routes load from this package's `boot()`, so a
binding made in a host provider's `boot()` can land after the route has already been decided.

## Testing

```bash
composer test
```

## Status

Scaffold. Built in reviewable PRs per module; review by Lennert and Mischa.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
