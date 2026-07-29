<?php

namespace Onomahq\Gezel\Concerns;

use Onomahq\Gezel\Contracts\ContainerBearerIssuer;
use Onomahq\Gezel\Contracts\GezelOwner;
use Onomahq\Gezel\Exceptions\ContainerLifecycleDisabledException;
use Onomahq\Gezel\GezelOrchestrator;

/**
 * Tears down the owner's Gezel container and revokes its bearer. The package
 * never calls this automatically. Apps wire it into whichever event actually
 * ends the owner's lifetime — typically a `deleting` observer, but an app that
 * deactivates rather than deletes should call it there instead. No-ops when
 * the owner was never provisioned.
 *
 * @phpstan-require-implements GezelOwner
 */
trait DeprovisionsGezelContainer
{
    public function deprovisionGezelContainer(): void
    {
        if (! $this->gezelProvisioned()) {
            return;
        }

        $issuer = app(ContainerBearerIssuer::class);
        $principalIds = $issuer->activePrincipalIds($this);

        try {
            app(GezelOrchestrator::class)->deprovision($this->gezel_id);
        } catch (ContainerLifecycleDisabledException) {
            // Docker unavailable (dev/test): nothing running to tear down.
        }

        $issuer->revoke($this, $principalIds);
    }
}
