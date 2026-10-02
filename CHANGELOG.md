# Changelog

All notable changes to `laravel-gezel` will be documented in this file.

## [0.3.3] - 2026-10-02

**Added**
- `gezel.connection` (`GEZEL_CONNECTION`) names the database connection of the owner table and `gezel_usage_events`,
  for an app that keeps the people it serves on a connection of their own. `GezelUsageEvent` and both published
  migrations follow it; null uses the default connection.

## [0.3.2] - 2026-10-02

**Changed**
- `laravel/mcp` ^1.0 is supported beside ^0.8 and ^0.9. The package's MCP surface (`Server`, `Server\Tool`,
  `Response::error()`, `shouldRegister()`) is unchanged in 1.0; the suite passes on 0.9.6 and 1.0.1.

## [0.3.1] - 2026-09-23

**Fixed**
- `ensureGezelId()` no longer overwrites a gezel_id another instance of the same owner already
  persisted. `ProvisionContainer::dispatchSync($owner)` runs through the sync queue, which restores
  the owner from the database, so the id the job minted never reached the caller's instance. The
  caller's next `ensureGezelId()` minted and saved a second id with no container behind it, and
  every later call answered 503 `container for user … is not connected`. The id is now minted with
  a conditional update and read back.
- Minting a gezel_id no longer fires the owner's model events and no longer persists unrelated
  dirty attributes on the instance.

**Upgrading from 0.3.0**
An app that provisions with `ProvisionContainer::dispatchSync()` may hold owners whose stored
gezel_id has no container: `GezelOrchestrator::healthCheck()` returns 404 for them. Re-dispatching
does not repair them, because `gezel_provisioned_at` is set and the job only re-syncs usage config.
Point the owner's gezel_id back at the id the middleware registered, then restart the container.
Match each owner exactly before writing: with a principal verifier configured, the middleware logs
`verifier/registry user mismatch principal_user=<stored id> registry_user=<registered id>` when the
container reconnects, which pairs the two through the container's own bearer. A timestamp match
between the `containers` table's `created_at` and `gezel_provisioned_at` only corroborates it.
Skip any owner whose match is ambiguous: a wrong pair puts one owner's agent on another's memory.

## [0.3.0] - 2026-07-29

Server-side compute lands: apps on Gezel reach the middleware's LLM, embeddings and transcription
endpoints through this package instead of each writing their own client. Metering moves with it,
from a dollar pricing table to a single token cap owned by the middleware.

**Breaking**
- Removed `gezel.owner.acknowledges_shared_memory`. An agent is always personal. That flag let a
  model standing for a group (a team, a workspace) own a container, which puts one agent memory in
  front of every member of it. `Owner::guard()` now refuses any owner model that is not
  `Authenticatable`, with no config to override it. Point `gezel.owner.model` at the model your
  users log in as.
- Removed the usage pricing table: `gezel.usage.pricing`, `gezel.usage.monthly_cap_usd` and the
  `usage_cap_usd` owner column, replaced by `gezel.usage.monthly_token_cap` and `usage_token_cap`.
  `GezelUsageEvent` drops `cost_usd` and `pricing_version`. Enforcement is one input+output token
  cap and the middleware owns it. Re-publish the migrations and migrate.
- `ComputeUsageRecorder::record()` drops its `: void` return type, so an app whose recorder returns
  its own row model implements the contract directly instead of wrapping it in a pass-through.

**Added**
- Compute clients over the middleware: `LlmClient`, `EmbeddingsClient`, `TranscriptionClient`.
  Timeout and retry count are per-call arguments. Usage lands through the `ComputeUsageRecorder`
  seam — bind your own to route it into an existing ledger, or keep the default and read
  `gezel_usage_events`.
- `Support\TurnContext`, a collector that takes a line and the question it resolves in one call, so
  a section gated out on empty data cannot leave behind a `resolved` entry claiming the agent knows
  something it was never told. `Support\PromptField` sanitises the values those lines carry.
- `GezelOrchestrator::usageStatus()` — the middleware's month-to-date number, which is the
  authoritative one; an app's own ledger holds only the callbacks it received.
- `GezelClient::proxyBaseUrl()` — the per-owner proxy root, for a caller that streams and needs the URL
  for its own long-lived HTTP client rather than a request through this one.
- Per-route registration toggles, `gezel.routes.agent_messages` and
  `gezel.routes.principals_verify`, both default on. An app that serves one of those paths itself
  turns the package's off, so deleting the app's route leaves a 404 rather than silently activating
  the package's underneath with different auth and a different response shape. `usage` stays
  registered regardless: the middleware POSTs to a hardcoded path and dead-letters on a 404.
- A warning on an unmetered compute call. A call for an owner with no `gezel_id` bills nothing and
  counts against no cap. The fail-open is deliberate; the silence was not.
- `gezel:health` checks the inbound service token. `app_token` proves itself on every outbound call,
  so a wrong one fails immediately, but `service_token` is only ever presented by the middleware
  calling in — a blank one looks healthy from here and refuses every callback in production.
- `SanctumIssuer`'s token name is configurable, so an app already minting container bearers under
  another label can adopt the driver without a fleet-wide rotation.

**Changed**
- The turn-context route takes a bound `TurnContextProvider`, not just
  `gezel.turn_context.enabled`. The flag on its own leaves the package's null default in place,
  which answers `{turn_context: null}` forever; the middleware reads that as "no grounding" and
  relays the turn anyway, so a miswired app loses grounding on every relayed turn with nothing
  erroring anywhere. No binding, no route, and the middleware gets the 404 it already handles. Bind
  the provider in a service provider's `register()` — routes load from this package's `boot()`, so
  a binding made in a host provider's `boot()` can land after the route has been decided.
- `GezelClient::activateSession()` and `deleteSession()` log what they swallow. A shared package
  that eats errors silently is worse than an app that does: the next app inherits the blindness
  without reading the source.
- `laravel/mcp` `^0.8` is accepted alongside `^0.9`.
