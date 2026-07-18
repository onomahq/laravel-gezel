<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Onomahq\Gezel\HealthStatus;

it('constructs under Date::use(CarbonImmutable) as consuming apps do', function () {
    Date::use(CarbonImmutable::class);

    try {
        expect(HealthStatus::healthy(42)->lastCheck)->toBeInstanceOf(CarbonImmutable::class)
            ->and(HealthStatus::unhealthy('down')->healthy)->toBeFalse();
    } finally {
        Date::useDefault();
    }
});
