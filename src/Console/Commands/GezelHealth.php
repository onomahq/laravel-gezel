<?php

namespace Onomahq\Gezel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Onomahq\Gezel\GezelOrchestrator;
use Onomahq\Gezel\Support\Owner;

/**
 * Checks middleware reachability, then guards against the confused-token-pair
 * class of incident: an app_token that authenticates fine but was minted for
 * a different app_id than this one is configured with.
 *
 * The middleware's /health endpoint is unauthenticated and app-agnostic. It
 * never reports which app_id a bearer resolves to, so that can't be asserted
 * directly. What it does expose: `/v1/containers/{gezel_id}/health` is scoped
 * to whatever app the bearer resolves to, and returns 404 for a gezel_id that
 * belongs to a different app. Health-checking one of our own already-
 * provisioned owners turns that into the real assertion. 200 means our
 * app_token owns this container, 404 means it authenticates as some other
 * app entirely.
 */
class GezelHealth extends Command
{
    protected $signature = 'gezel:health';

    protected $description = 'Check middleware reachability and that gezel.app_token resolves to gezel.app_id.';

    public function handle(GezelOrchestrator $orchestrator): int
    {
        if (! $this->middlewareReachable()) {
            return self::FAILURE;
        }

        $appId = config('gezel.app_id');

        if (blank($appId)) {
            $this->error('gezel.app_id is not configured.');

            return self::FAILURE;
        }

        // The middleware POSTs usage callbacks to the literal path
        // /api/v1/internal/usage; a moved prefix means every metering event
        // 404s and dead-letters permanently. Fail here, at deploy time, not
        // silently in the ledger.
        $prefix = trim((string) config('gezel.routes.prefix'), '/');

        if (config('gezel.usage.enabled', true) && $prefix !== 'api/v1/internal') {
            $this->error("The middleware posts usage callbacks to the hardcoded path /api/v1/internal/usage, but gezel.routes.prefix is '{$prefix}' — metering callbacks will 404 and dead-letter. Restore the prefix or set gezel.usage.enabled = false.");

            return self::FAILURE;
        }

        $owner = Owner::model()::query()
            ->whereNotNull('gezel_provisioned_at')
            ->whereNotNull('gezel_id')
            ->first();

        if ($owner === null) {
            $this->warn("app_token authentication unverified: no provisioned owner exists to test container-scoped access against. Configured app_id: {$appId}.");

            return self::SUCCESS;
        }

        $gezelId = $owner->gezel_id ?? null;

        if (! is_string($gezelId) || $gezelId === '') {
            $this->warn("app_token authentication unverified: no provisioned owner exists to test container-scoped access against. Configured app_id: {$appId}.");

            return self::SUCCESS;
        }

        $status = $orchestrator->healthCheck($gezelId);

        if ($status->healthy) {
            $this->info("app_token authenticates and resolves to a container it owns (verified against owner {$owner->getKey()}, app_id {$appId}).");

            return self::SUCCESS;
        }

        $this->error("app_token failed the container-scoped check ({$status->error}). This usually means gezel.app_token belongs to a different app than gezel.app_id={$appId} (a confused token pair), or the container was torn down outside the app.");

        return self::FAILURE;
    }

    private function middlewareReachable(): bool
    {
        try {
            $response = Http::baseUrl(config('gezel.middleware.url'))
                ->timeout(config('gezel.timeout'))
                ->get('/health');
        } catch (ConnectionException $e) {
            $this->error('Cannot reach the middleware at '.config('gezel.middleware.url').": {$e->getMessage()}");

            return false;
        }

        if (! $response->successful()) {
            $this->error("Middleware /health returned {$response->status()}.");

            return false;
        }

        $this->info(sprintf(
            'Middleware reachable (docker=%s, containers=%d, application_configured=%s).',
            $response->json('docker') ? 'yes' : 'no',
            (int) $response->json('containers.total', 0),
            $response->json('application.configured') ? 'yes' : 'no',
        ));

        return true;
    }
}
