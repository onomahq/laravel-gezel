<?php

namespace Onomahq\Gezel\Contracts;

use Onomahq\Gezel\Usage\UsageRecorder;

/**
 * Where a compute client writes the token counts it just spent.
 *
 * A seam rather than a direct dependency on {@see UsageRecorder}
 * because an app that already keeps its own token ledger (Onoma's `token_usage`,
 * feeding its billing and admin observability) must keep writing to it. The
 * default binding records to the package's own `gezel_usage_events` table, so
 * an app that has no ledger of its own gets one for free.
 */
interface ComputeUsageRecorder
{
    /**
     * @param  array{
     *     user_id: string,
     *     source: string,
     *     provider: string,
     *     model: string,
     *     phase?: ?string,
     *     input_tokens: int,
     *     output_tokens: int,
     *     cache_creation_tokens?: int,
     *     cache_read_tokens?: int,
     *     context?: array<string, mixed>,
     * }  $event
     */
    public function record(array $event): void;
}
