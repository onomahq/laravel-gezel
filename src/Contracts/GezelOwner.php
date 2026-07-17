<?php

namespace Onomahq\Gezel\Contracts;

use Onomahq\Gezel\Concerns\HasGezelAgent;

/**
 * The contract {@see HasGezelAgent} fulfills. Owner
 * models pass this alongside Model wherever code needs to call these methods
 * on a generically-typed owner, so the type system enforces what config's own
 * comment already requires: gezel.owner.model must use the trait.
 */
interface GezelOwner
{
    public function ensureGezelId(): string;

    public function gezelProvisioned(): bool;

    public function gezelOptedIn(): bool;

    public function optIntoGezel(): void;
}
