# laravel-gezel

[![GitHub Tests Action Status](https://github.com/onomahq/laravel-gezel/actions/workflows/run-tests.yml/badge.svg)](https://github.com/onomahq/laravel-gezel/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://github.com/onomahq/laravel-gezel/actions/workflows/fix-php-code-style-issues.yml/badge.svg)](https://github.com/onomahq/laravel-gezel/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)

Shared Laravel package for apps built on Gezel, the per-user agent runtime behind the middleware gateway. Used by Onoma Platform, Stagent and Calmunity.

Spec: `research/26-07-16-laravel-gezel-package.md` in [onomahq/onoma](https://github.com/onomahq/onoma).

## What it ships

- `config/gezel.php` — one canonical config (middleware URL, app token, service token)
- Migration stub + `HasGezelAgent` trait on a configurable owner model (default `User`)
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

## Testing

```bash
composer test
```

## Status

Scaffold. Built in reviewable PRs per module; review by Lennert and Mischa.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
