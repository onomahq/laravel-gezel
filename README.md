# laravel-gezel

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

## Status

Pre-scaffold. Built in reviewable PRs per module; review by Lennert and Mischa.
