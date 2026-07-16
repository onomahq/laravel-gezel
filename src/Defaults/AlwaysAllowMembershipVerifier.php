<?php

namespace Onomahq\Gezel\Defaults;

use Illuminate\Database\Eloquent\Model;
use Onomahq\Gezel\Contracts\OwnerMembershipVerifier;

/**
 * Ships as the default {@see OwnerMembershipVerifier}. Correct for the
 * default User owner — a container principal already scopes identity to
 * exactly one row, so there is nothing further to check. Team-like owners
 * override this binding with a real dissolution/membership check.
 */
final class AlwaysAllowMembershipVerifier implements OwnerMembershipVerifier
{
    public function verify(Model $owner): bool
    {
        return true;
    }
}
