<?php

namespace Onomahq\Gezel\Defaults;

use Illuminate\Database\Eloquent\Model;
use Onomahq\Gezel\Contracts\TurnContextProvider;
use Onomahq\Gezel\Support\Viewing;

/**
 * Ships as the default {@see TurnContextProvider} so an app that hasn't
 * wired its own composer yet still answers the callback cleanly, with no
 * context, per the endpoint's optional-by-contract behavior.
 */
final class NullTurnContextProvider implements TurnContextProvider
{
    public function compose(Model $owner, ?Viewing $viewing = null): ?string
    {
        return null;
    }
}
