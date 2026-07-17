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
use Onomahq\Gezel\Contracts\ContainerBearerIssuer;
use Onomahq\Gezel\Contracts\GezelOwner;
use Onomahq\Gezel\Exceptions\ContainerLifecycleDisabledException;
use Onomahq\Gezel\GezelOrchestrator;
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

    public function uniqueId(): string
    {
        return (string) $this->owner->getKey();
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

    public function handle(ContainerBearerIssuer $issuer, GezelOrchestrator $orchestrator): void
    {
        if ($this->owner->gezelProvisioned()) {
            return;
        }

        $gezelId = $this->owner->ensureGezelId();
        $bearer = $issuer->issue($this->owner);

        try {
            $container = $orchestrator->provision($gezelId, $bearer);
        } catch (ContainerLifecycleDisabledException) {
            // Dev/test env without Docker: middleware refused container
            // provisioning. The bearer just minted authenticates nothing, so
            // revoke it rather than leave a live, orphaned token behind.
            $this->revokeMintedBearer($issuer);

            return;
        } catch (Throwable $e) {
            $this->revokeMintedBearer($issuer);

            throw $e;
        }

        $this->owner->forceFill(['gezel_provisioned_at' => now()])->save();

        Log::info('Gezel container provisioned', [
            'owner_id' => $this->owner->getKey(),
            'gezel_id' => $gezelId,
            'container_id' => $container->containerId,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Failed to provision Gezel container', [
            'owner_id' => $this->owner->getKey(),
            'error' => $exception->getMessage(),
        ]);
    }

    private function revokeMintedBearer(ContainerBearerIssuer $issuer): void
    {
        $issuer->revoke($this->owner, $issuer->activePrincipalIds($this->owner));
    }
}
