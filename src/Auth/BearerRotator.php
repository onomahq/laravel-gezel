<?php

namespace Onomahq\Gezel\Auth;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Onomahq\Gezel\Contracts\ContainerBearerIssuer;

/**
 * Enforces the safe order for rotating a container bearer: mint the new
 * token (already durable — issuing it persists the token row), push it to
 * the middleware, and only delete the old token once that push succeeds. A
 * failed middleware call leaves the previous, still-working bearer in place
 * instead of locking the container out.
 *
 * The whole sequence runs under a per-owner lock so a scheduled reconcile
 * and a manual recreate can never interleave and race each other's
 * mint/delete steps.
 *
 * Callers — always a queued job's `handle()`, never its constructor. A
 * plaintext bearer captured in a constructor gets serialized into
 * `failed_jobs`/Horizon; minting must happen once the job actually runs.
 */
final class BearerRotator
{
    public function __construct(private readonly ContainerBearerIssuer $issuer) {}

    /**
     * @param  callable(string $bearer): void  $pushToMiddleware  Push the new bearer to the middleware (e.g. writeConfig). Throwing aborts the rotation before $deleteOldBearers runs.
     * @param  callable(): void  $deleteOldBearers  Revoke the owner's previous bearer(s). Only called after $pushToMiddleware succeeds.
     */
    public function rotate(Model $owner, callable $pushToMiddleware, callable $deleteOldBearers): string
    {
        $gezelId = $owner->gezel_id ?? null;

        return Cache::lock("gezel-bearer-rotate:{$gezelId}", 30)->block(10, function () use ($owner, $pushToMiddleware, $deleteOldBearers): string {
            $bearer = $this->issuer->issue($owner);

            $pushToMiddleware($bearer);
            $deleteOldBearers();

            return $bearer;
        });
    }
}
