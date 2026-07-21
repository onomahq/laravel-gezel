<?php

namespace Onomahq\Gezel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Onomahq\Gezel\Auth\BearerRotator;
use Onomahq\Gezel\Contracts\ContainerBearerIssuer;
use Onomahq\Gezel\Contracts\GezelOwner;
use Onomahq\Gezel\Exceptions\ContainerLifecycleDisabledException;
use Onomahq\Gezel\GezelOrchestrator;
use Onomahq\Gezel\Usage\UsageConfigSync;
use Throwable;

/**
 * Provisions the owner's Gezel container. The bearer is minted here, inside
 * handle(), never taken as a constructor property: a plaintext bearer
 * captured at dispatch time gets serialized into failed_jobs/Horizon, where
 * it would sit in plaintext long after the job itself is gone.
 *
 * ShouldBeUnique, keyed on the owner, stops two dispatches for the same
 * not-yet-provisioned owner (an observer-fired dispatch racing an hourly
 * self-heal tick, or two self-heal ticks) from each minting their own bearer
 * at once. afterCommit defers dispatch until the row that triggered it (the
 * observer's `created`, HasGezelAgent::optIntoGezel()) is actually visible
 * outside its transaction.
 */
class ProvisionContainer implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(public Model&GezelOwner $owner)
    {
        // Set here, not as a property default: Queueable already declares
        // $afterCommit (untyped, defaulting to null), and PHP treats a class
        // redeclaring a trait's property with a different default as a fatal
        // conflict rather than a silent override.
        $this->afterCommit = true;
    }

    /**
     * Prefixed with app_id like every other Gezel lock key ({@see BearerRotator}):
     * owner primary keys collide across apps, so Onoma's owner 1 must not
     * share a unique-job lock with Stagent's owner 1 on a shared cache store.
     */
    public function uniqueId(): string
    {
        return sprintf('gezel:%s:provision-container:%s', config('gezel.app_id'), $this->owner->getKey());
    }

    public function uniqueVia(): Repository
    {
        return Cache::store(config('gezel.lock_store'));
    }

    /**
     * Must outlive every retry: a lock that expired mid-backoff would let a
     * concurrent self-heal tick mint a second bearer for the same owner.
     */
    public function uniqueFor(): int
    {
        return ((int) config('gezel.timeout', 120) + 30) * $this->tries;
    }

    public function handle(ContainerBearerIssuer $issuer, GezelOrchestrator $orchestrator, UsageConfigSync $usageSync): void
    {
        if ($this->owner->gezelProvisioned()) {
            // The container exists but the middleware enforces metering
            // fail-closed, so a re-run's one useful act is refreshing the
            // token cap (a cap change, or a fleet that provisioned before
            // enforcement existed).
            $this->syncUsageConfig($usageSync);

            return;
        }

        // Captured before minting so a failure only revokes the bearer this
        // attempt just created, never a bearer some other process already
        // has live for this owner (e.g. a previous attempt that provisioned
        // successfully but died before the forceFill/save below committed).
        $previousPrincipalIds = $issuer->activePrincipalIds($this->owner);

        $gezelId = $this->owner->ensureGezelId();
        $bearer = $issuer->issue($this->owner);

        try {
            $container = $orchestrator->provision($gezelId, $bearer);
        } catch (ContainerLifecycleDisabledException) {
            // Dev/test env without Docker: middleware refused container
            // provisioning. The bearer just minted authenticates nothing, so
            // revoke it rather than leave a live, orphaned token behind.
            $this->revokeMintedBearer($issuer, $previousPrincipalIds);

            return;
        } catch (Throwable $e) {
            $this->revokeMintedBearer($issuer, $previousPrincipalIds);

            throw $e;
        }

        $this->owner->forceFill(['gezel_provisioned_at' => now()])->save();

        // After the provisioned_at save: a sync failure now throws into the
        // job's retry, and the retry lands in the already-provisioned branch
        // above, which re-syncs without re-provisioning.
        $this->syncUsageConfig($usageSync);

        Log::info('Gezel container provisioned', [
            'owner_id' => $this->owner->getKey(),
            'gezel_id' => $gezelId,
            'container_id' => $container->containerId,
        ]);
    }

    private function syncUsageConfig(UsageConfigSync $usageSync): void
    {
        if (! config('gezel.usage.enabled', true)) {
            return;
        }

        $usageSync->sync($this->owner);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Failed to provision Gezel container', [
            'owner_id' => $this->owner->getKey(),
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * @param  array<int, int|string>  $previousPrincipalIds
     */
    private function revokeMintedBearer(ContainerBearerIssuer $issuer, array $previousPrincipalIds): void
    {
        $newPrincipalIds = array_values(array_diff($issuer->activePrincipalIds($this->owner), $previousPrincipalIds));

        $issuer->revoke($this->owner, $newPrincipalIds);
    }
}
