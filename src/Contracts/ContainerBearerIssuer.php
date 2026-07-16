<?php

namespace Onomahq\Gezel\Contracts;

use Illuminate\Database\Eloquent\Model;
use Onomahq\Gezel\Auth\BearerRotator;

/**
 * Mints a per-owner bearer for container→app calls (MCP + agent-messages).
 * Called at provision/recreate time; see {@see BearerRotator}
 * for the choreography that rotates an existing bearer safely.
 */
interface ContainerBearerIssuer
{
    public function issue(Model $owner): string;
}
