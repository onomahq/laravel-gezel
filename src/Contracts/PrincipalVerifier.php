<?php

namespace Onomahq\Gezel\Contracts;

use Onomahq\Gezel\Auth\GezelPrincipal;
use Onomahq\Gezel\Auth\PrincipalGate;

/**
 * Resolves a bearer string presented by the middleware's verify callback to
 * the container principal behind it. Implementations delegate the token-family
 * check (Sanctum PAT, Passport token) to a driver, but every driver's result
 * passes through {@see PrincipalGate} before it can be
 * returned here — drivers never answer authoritatively on their own.
 */
interface PrincipalVerifier
{
    public function verify(string $bearer): ?GezelPrincipal;
}
