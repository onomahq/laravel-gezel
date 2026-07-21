<?php

namespace Onomahq\Gezel\Usage;

use Illuminate\Database\Eloquent\Model;
use Onomahq\Gezel\Contracts\GezelOwner;
use Onomahq\Gezel\GezelOrchestrator;

/**
 * Pushes an owner's monthly token cap to the middleware, which enforces
 * metering fail-closed: an owner without a pushed config gets a 503 on every
 * agent turn and transcription.
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
        $gezelId = $owner->getAttribute('gezel_id');

        if (! is_string($gezelId) || $gezelId === '') {
            return;
        }

        $this->orchestrator->writeConfig($gezelId, $this->payload($owner), (int) now()->format('Uu'));
    }

    /**
     * @return array{usage: array{monthly_token_cap: int}}
     */
    public function payload(Model&GezelOwner $owner): array
    {
        return [
            'usage' => [
                'monthly_token_cap' => (int) ($owner->getAttribute('usage_token_cap') ?? config('gezel.usage.monthly_token_cap', 6000000)),
            ],
        ];
    }
}
