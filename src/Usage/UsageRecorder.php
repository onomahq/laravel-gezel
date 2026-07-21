<?php

namespace Onomahq\Gezel\Usage;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Onomahq\Gezel\Models\GezelUsageEvent;
use Throwable;

/**
 * Persists one usage callback event. Coercing, never rejecting: the
 * middleware dead-letters an event permanently on any 4xx except 408/429, so
 * a payload shape this recorder didn't anticipate must still become a row.
 * Missing event_id gets a minted uuid (such an event cannot be deduplicated
 * across retries — the middleware always sends one, so this is a fallback,
 * not the path). Retried events are a no-op via the unique event_id.
 *
 * The ledger records raw token counts only — no cost. The middleware is the
 * cap authority and enforces on input+output tokens; the package just keeps
 * the token ledger for reporting.
 */
class UsageRecorder
{
    /**
     * @param  array<string, mixed>  $event
     */
    public function record(array $event): GezelUsageEvent
    {
        $context = is_array($event['context'] ?? null) ? $event['context'] : [];

        return GezelUsageEvent::query()->firstOrCreate(
            ['event_id' => $this->string($event['event_id'] ?? null) ?? (string) Str::uuid()],
            [
                'gezel_id' => $this->string($event['user_id'] ?? null),
                'source' => $this->string($event['source'] ?? null),
                'provider' => $this->string($event['provider'] ?? null),
                'model' => $this->string($event['model'] ?? null),
                'phase' => $this->string($event['phase'] ?? null),
                'input_tokens' => $this->tokens($event['input_tokens'] ?? null),
                'output_tokens' => $this->tokens($event['output_tokens'] ?? null),
                'cache_creation_tokens' => $this->tokens($event['cache_creation_tokens'] ?? null),
                'cache_read_tokens' => $this->tokens($event['cache_read_tokens'] ?? null),
                'occurred_at' => $this->occurredAt($event['occurred_at'] ?? null),
                'context' => $context,
            ],
        );
    }

    protected function string(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }

    protected function tokens(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    protected function occurredAt(mixed $value): Carbon
    {
        if (is_string($value) && $value !== '') {
            try {
                return Carbon::parse($value);
            } catch (Throwable) {
                // fall through to now()
            }
        }

        return now();
    }
}
