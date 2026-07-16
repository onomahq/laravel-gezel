<?php

namespace Onomahq\Gezel\Auth;

use Carbon\CarbonImmutable;

/**
 * `ownerId` (local PK) and `gezelId` (middleware-facing key) are kept as
 * distinct fields on purpose: local queries must use `ownerId`, and anything
 * that crosses back to the middleware (the `/principals/verify` response,
 * inbound resolution by the callback controllers) must use `gezelId`. Never
 * substitute one for the other — see architecture/MULTI-TENANCY.md and
 * Module 5 of research/26-07-16-laravel-gezel-package.md.
 *
 * A GezelPrincipal only ever exists as the container kind in active status —
 * {@see PrincipalGate::admit()} returns null for anything else — so `kind`
 * and `status` are the literal wire values the APP-CONTRACT §2c response
 * expects (`gezel_container`, `active`), not enums standing in for
 * possibilities the gate never actually produces.
 */
final readonly class GezelPrincipal
{
    /**
     * @param  array<int, string>  $scopes
     */
    public function __construct(
        public string $ownerId,
        public string $gezelId,
        public string $principalId,
        public string $kind,
        public string $status,
        public ?CarbonImmutable $expiresAt,
        public array $scopes,
    ) {}
}
