<?php

namespace Onomahq\Gezel\Auth;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A driver's raw read of a token, before {@see PrincipalGate} decides whether
 * it actually admits. Every field here is a fact lifted directly off the
 * token record itself (never off request input) — the gate, not the driver,
 * decides what those facts mean.
 */
final readonly class TokenCandidate
{
    /**
     * @param  array<int, string>  $scopes
     */
    public function __construct(
        public Model $owner,
        public string $principalId,
        public string $tokenName,
        public string $expectedTokenName,
        public bool $revoked,
        public ?CarbonImmutable $expiresAt,
        public array $scopes,
    ) {}
}
