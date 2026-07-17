<?php

namespace Onomahq\Gezel;

use Illuminate\Support\Carbon;

final class HealthStatus
{
    public function __construct(
        public readonly bool $healthy,
        public readonly Carbon $lastCheck,
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
