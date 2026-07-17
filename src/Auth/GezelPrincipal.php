<?php

namespace Onomahq\Gezel\Auth;

use Carbon\CarbonImmutable;

/**
 * `ownerId` (local PK) and `gezelId` (middleware-facing key) are kept as
 * distinct fields on purpose: local queries must use `ownerId`, and anything
 * that crosses back to the middleware (the `/principals/verify` response,
 * inbound resolution by the callback controllers) must use `gezelId`. Never
 * substitute one for the other, see architecture/MULTI-TENANCY.md and
 * Module 5 of research/26-07-16-laravel-gezel-package.md.
 *
 * `kind` and `status` are fixed values, not constructor arguments: a
 * GezelPrincipal only ever exists as the container kind in active status,
 * because {@see PrincipalGate::admit()} returns null for anything else. Taking
 * them as arguments would let a driver return a principal it declared active
 * without the gate ever agreeing, which is the exact shortcut Module 5 exists
 * to prevent (Stagent's shipped verifier hardcodes `status: 'active'` today).
 * The values are the literal wire strings the APP-CONTRACT §2c response wants.
 */
final readonly class GezelPrincipal
{
    public const KIND = 'gezel_container';

    public const STATUS = 'active';

    public string $kind;

    public string $status;

    /**
     * @param  array<int, string>  $scopes
     */
    public function __construct(
        public string $ownerId,
        public string $gezelId,
        public string $principalId,
        public ?CarbonImmutable $expiresAt,
        public array $scopes,
    ) {
        $this->kind = self::KIND;
        $this->status = self::STATUS;
    }
}
