<?php

namespace Onomahq\Gezel\Usage;

use Illuminate\Database\Eloquent\Model;
use Onomahq\Gezel\Contracts\GezelOwner;
use Onomahq\Gezel\GezelOrchestrator;

/**
 * Pushes an owner's usage config (cap + pricing) to the middleware, which
 * enforces metering fail-closed: an owner without a pushed config gets a 503
 * on every agent turn and transcription.
 *
 * Deliberately no transaction or row lock around the push: the version is a
 * microsecond timestamp, so it is monotonic by construction, and the
 * middleware skips any section whose version is <= the stored one. Two
 * concurrent syncs therefore already resolve correctly — the later state
 * wins, the stale push is an idempotent no-op. A lock here would hold a DB
 * row across a network round-trip for nothing (the same reasoning that keeps
 * BearerRotator out of transactions).
 */
class UsageConfigSync
{
    public function __construct(protected GezelOrchestrator $orchestrator) {}

    public function sync(Model&GezelOwner $owner): void
    {
        $gezelId = $owner->gezel_id;

        if (! is_string($gezelId) || $gezelId === '') {
            return;
        }

        $this->orchestrator->writeConfig($gezelId, $this->payload($owner), (int) now()->format('Uu'));
    }

    /**
     * @return array{usage: array{monthly_cap_usd: float, pricing_version: int, prices: array<string, array<string, float>>}}
     */
    public function payload(Model&GezelOwner $owner): array
    {
        return [
            'usage' => [
                'monthly_cap_usd' => (float) ($owner->usage_cap_usd ?? config('gezel.usage.monthly_cap_usd', 20)),
                'pricing_version' => (int) config('gezel.usage.pricing.version', 1),
                'prices' => config('gezel.usage.pricing.models', []),
            ],
        ];
    }
}
