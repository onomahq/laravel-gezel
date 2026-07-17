<?php

namespace Onomahq\Gezel\Auth;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A driver's raw read of a token, before {@see PrincipalGate} decides whether
 * it actually admits. Every field here is a fact lifted directly off the token
 * record itself (never off request input), and the gate, not the driver,
 * decides what those facts mean. The name the token is expected to carry is
 * not such a fact, so it is an argument to {@see PrincipalGate::admit()}
 * instead of a field here.
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
        public bool $revoked,
        public ?CarbonImmutable $expiresAt,
        public array $scopes,
    ) {}
}
