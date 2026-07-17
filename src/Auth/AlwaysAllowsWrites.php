<?php

namespace Onomahq\Gezel\Auth;

use Illuminate\Database\Eloquent\Model;
use Onomahq\Gezel\Contracts\WritesGate;

/**
 * The package's default {@see WritesGate}: every owner may write. Bound with
 * bindIf so a host app's own gate, bound before the package boots, always wins.
 */
final class AlwaysAllowsWrites implements WritesGate
{
    public function writesEnabled(Model $owner): bool
    {
        return true;
    }
}
