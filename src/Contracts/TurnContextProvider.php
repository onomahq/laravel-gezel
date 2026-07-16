<?php

namespace Onomahq\Gezel\Contracts;

use Illuminate\Database\Eloquent\Model;
use Onomahq\Gezel\Support\Viewing;

/**
 * Composes the ephemeral turn-context block Gezel injects as a system
 * message ahead of a relayed turn (Telegram, or any channel that never
 * reaches the app directly). Web chat composes the same block inline in
 * the consumer's own chat controller — this interface only covers the
 * relay path.
 */
interface TurnContextProvider
{
    public function compose(Model $owner, ?Viewing $viewing = null): ?string;
}
