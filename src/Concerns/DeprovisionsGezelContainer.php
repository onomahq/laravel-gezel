<?php

namespace Onomahq\Gezel\Concerns;

use Onomahq\Gezel\Contracts\ContainerBearerIssuer;
use Onomahq\Gezel\Exceptions\ContainerLifecycleDisabledException;
use Onomahq\Gezel\GezelOrchestrator;

/**
 * Tears down the owner's Gezel container and revokes its bearer. The package
 * never calls this automatically. Apps wire it into whichever event actually
 * ends the owner's lifetime: a `deleting` observer for a User owner, or the
 * dissolution path for a non-User owner (a Team can disband without any User
 * being deleted). No-ops when the owner was never provisioned.
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
