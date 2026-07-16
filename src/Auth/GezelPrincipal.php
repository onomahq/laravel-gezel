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
        public PrincipalKind $kind,
        public PrincipalStatus $status,
        public ?CarbonImmutable $expiresAt,
        public array $scopes,
    ) {}
}
