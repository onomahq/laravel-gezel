<?php

namespace Onomahq\Gezel\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Gates delivery of an agent message to the resolved owner: confirms the owner
 * is still entitled to receive one before the package invokes the bound
 * {@see AgentMessageHandler}. The container principal already scopes identity
 * to exactly one row, so the default allows everything; an app that suspends
 * or deactivates an owner without deleting it binds a real check here.
 */
interface OwnerMembershipVerifier
{
    public function verify(Model $owner): bool;
}
