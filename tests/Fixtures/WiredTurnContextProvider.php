<?php

namespace Onomahq\Gezel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Onomahq\Gezel\Contracts\TurnContextProvider;
use Onomahq\Gezel\Support\Viewing;

/**
 * A host app's own composer, standing in for the wiring the turn-context route
 * requires. Composes nothing for these owners, which is a real answer from a
 * real provider — not the package's null default, which is what withholds the
 * route.
 */
class WiredTurnContextProvider implements TurnContextProvider
{
    public function compose(Model $owner, ?Viewing $viewing = null): ?string
    {
        return null;
    }
}
