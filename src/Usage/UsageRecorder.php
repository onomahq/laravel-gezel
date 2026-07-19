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
 * The middleware's context.cost_usd_local is the recorded cost: that is the
 * figure that actually enforced the cap. Local pricing only fills in when it
 * is absent, flagged pricing_fallback so divergence stays diagnosable.
 */
class UsageRecorder
{
    /**
     * @param  array<string, mixed>  $event
     */
    public function record(array $event): GezelUsageEvent
    {
        $context = is_array($event['context'] ?? null) ? $event['context'] : [];

        [$cost, $costContext] = $this->cost($event, $context);

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
                'cost_usd' => $cost,
                'pricing_version' => is_numeric($context['pricing_version'] ?? null) ? (int) $context['pricing_version'] : null,
                'occurred_at' => $this->occurredAt($event['occurred_at'] ?? null),
                // array_merge, not +: the recorder's own pricing flags must
                // win over a same-named key the middleware happened to send.
                'context' => array_merge($context, $costContext),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $context
     * @return array{0: float, 1: array<string, mixed>}
     */
    protected function cost(array $event, array $context): array
    {
        if (is_numeric($context['cost_usd_local'] ?? null)) {
            return [(float) $context['cost_usd_local'], []];
        }

        $provider = $this->string($event['provider'] ?? null);
        $model = $this->string($event['model'] ?? null);
        $prices = $this->priceFor($provider, $model);
        $flags = ['pricing_fallback' => true];

        if ($prices === null) {
            // Mirror the middleware: an unpriced model bills at the priciest
            // known rate, flagged — never a silent zero that understates a
            // monthly total.
            $prices = $this->maxRates();
            $flags['pricing_unknown'] = true;
        }

        if ($prices === null) {
            return [0.0, $flags];
        }

        $cost = ($this->tokens($event['input_tokens'] ?? null) * ($prices['input_per_million'] ?? 0.0)
            + $this->tokens($event['output_tokens'] ?? null) * ($prices['output_per_million'] ?? 0.0)
            + $this->tokens($event['cache_creation_tokens'] ?? null) * ($prices['cache_write_per_million'] ?? $prices['input_per_million'] ?? 0.0)
            + $this->tokens($event['cache_read_tokens'] ?? null) * ($prices['cache_read_per_million'] ?? $prices['input_per_million'] ?? 0.0)
        ) / 1_000_000;

        return [round($cost, 8), $flags];
    }

    /**
     * @return array<string, float>|null
     */
    protected function maxRates(): ?array
    {
        /** @var array<string, array<string, float>> $models */
        $models = config('gezel.usage.pricing.models', []);

        if ($models === []) {
            return null;
        }

        return [
            'input_per_million' => max(array_map(fn (array $p) => (float) ($p['input_per_million'] ?? 0.0), $models)),
            'output_per_million' => max(array_map(fn (array $p) => (float) ($p['output_per_million'] ?? 0.0), $models)),
        ];
    }

    /**
     * Exact "provider/model" match first, then the longest configured key
     * that is a prefix of it — mirrors the middleware's family matching.
     *
     * @return array<string, float>|null
     */
    protected function priceFor(?string $provider, ?string $model): ?array
    {
        if ($provider === null || $model === null) {
            return null;
        }

        /** @var array<string, array<string, float>> $models */
        $models = config('gezel.usage.pricing.models', []);
        $key = "{$provider}/{$model}";

        if (isset($models[$key])) {
            return $models[$key];
        }

        $family = null;

        foreach (array_keys($models) as $candidate) {
            if (str_starts_with($key, $candidate) && strlen($candidate) > strlen($family ?? '')) {
                $family = $candidate;
            }
        }

        return $family === null ? null : $models[$family];
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
