<?php

namespace Onomahq\Gezel\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Handles an unprompted agent message pushed from the container.
 *
 * Everything an implementation is handed has already been checked by the
 * package, which is the point of the package owning the controller: `$owner`
 * is resolved from the authenticated container principal and never from
 * request input; the owner is still current per the bound
 * {@see OwnerMembershipVerifier}; any target `$payload` names belongs to that
 * owner per the bound {@see TargetOwnershipVerifier}; and `$payload` carries
 * only keys the package validated. A naive implementation cannot skip a check
 * because it is never given the chance to perform one.
 */
interface AgentMessageHandler
{
    /**
     * @param  array<string, mixed>  $payload  Validated: `message`, plus any of TargetOwnershipVerifier::TARGET_KEYS.
     */
    public function handle(Model $owner, array $payload): void;
}
