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

    /**
     * The owner's currently live container-bearer token ids, captured before
     * {@see issue()} mints a replacement so the caller can revoke exactly
     * those tokens once the new one is confirmed working.
     *
     * @return array<int, int|string>
     */
    public function activePrincipalIds(Model $owner): array;

    /**
     * Revokes the given token ids. Never revokes by name/owner alone, only
     * ids captured via {@see activePrincipalIds()}, so a rotation in progress
     * on another process can't have its brand-new token deleted out from
     * under it.
     *
     * @param  array<int, int|string>  $principalIds
     */
    public function revoke(Model $owner, array $principalIds): void;
}
