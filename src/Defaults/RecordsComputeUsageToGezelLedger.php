<?php

namespace Onomahq\Gezel\Defaults;

use Onomahq\Gezel\Contracts\ComputeUsageRecorder;
use Onomahq\Gezel\Usage\UsageRecorder;

/**
 * The default {@see ComputeUsageRecorder}: writes to the package's own
 * `gezel_usage_events` ledger, the same table the middleware's usage callback
 * lands in. `user_id` carries the gezel_id, matching that callback's shape.
 */
final class RecordsComputeUsageToGezelLedger implements ComputeUsageRecorder
{
    public function __construct(private readonly UsageRecorder $recorder) {}

    public function record(array $event): void
    {
        $this->recorder->record($event);
    }
}
