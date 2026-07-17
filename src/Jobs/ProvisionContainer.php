<?php

namespace Onomahq\Gezel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Onomahq\Gezel\Contracts\ContainerBearerIssuer;
use Onomahq\Gezel\Exceptions\ContainerLifecycleDisabledException;
use Onomahq\Gezel\GezelOrchestrator;
use Throwable;

/**
 * Provisions the owner's Gezel container. The bearer is minted here, inside
 * handle(), never taken as a constructor property: a plaintext bearer
 * captured at dispatch time gets serialized into failed_jobs/Horizon, where
 * it would sit in plaintext long after the job itself is gone.
 */
class ProvisionContainer implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(public Model $owner) {}

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
            // provisioning. Nothing more to do until it's available.
            return;
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
}
