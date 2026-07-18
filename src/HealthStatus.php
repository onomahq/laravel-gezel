<?php

namespace Onomahq\Gezel;

use Carbon\CarbonInterface;

final class HealthStatus
{
    public function __construct(
        public readonly bool $healthy,
        // CarbonInterface, not Illuminate\Support\Carbon: now() yields
        // CarbonImmutable in apps that Date::use(CarbonImmutable::class).
        public readonly CarbonInterface $lastCheck,
        public readonly ?int $uptimeSeconds = null,
        public readonly ?string $error = null,
    ) {}

    public static function healthy(?int $uptimeSeconds = null): self
    {
        return new self(
            healthy: true,
            lastCheck: now(),
            uptimeSeconds: $uptimeSeconds,
        );
    }

    public static function unhealthy(string $error): self
    {
        return new self(
            healthy: false,
            lastCheck: now(),
            error: $error,
        );
    }
}
