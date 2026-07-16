<?php

namespace Onomahq\Gezel\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Gates delivery of an agent message to the resolved owner. Trivial for a
 * User owner — the container principal already scopes identity to exactly
 * one row. Real for a Team-like owner: verifies the owner entity itself is
 * still current (not dissolved/removed) before the package invokes the
 * bound {@see AgentMessageHandler}. Acting-member-level authorization (which
 * specific member of a shared owner is acting) is not solved generically in
 * v1 — see Module 2 of research/26-07-16-laravel-gezel-package.md.
 */
interface OwnerMembershipVerifier
{
    public function verify(Model $owner): bool;
}
