<?php

namespace Onomahq\Gezel\Defaults;

use Illuminate\Database\Eloquent\Model;
use Onomahq\Gezel\Contracts\OwnerMembershipVerifier;

/**
 * Ships as the default {@see OwnerMembershipVerifier}. Correct wherever an
 * owner's existence is its entitlement: the container principal already scopes
 * identity to exactly one row, so there is nothing further to check. An app
 * that can suspend an owner in place overrides this binding.
 */
final class AlwaysAllowMembershipVerifier implements OwnerMembershipVerifier
{
    public function verify(Model $owner): bool
    {
        return true;
    }
}
