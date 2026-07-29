<?php

namespace Onomahq\Gezel\Compute;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Onomahq\Gezel\Contracts\GezelOwner;
use RuntimeException;

/**
 * Shared transport for the middleware's compute endpoints (`/v1/chat/completions`,
 * `/v1/embeddings`, `/v1/transcribe`). The middleware holds every provider key;
 * an app only ever relays payloads through it.
 *
 * The `X-Usage-*` headers are what make a call metered. They carry the owner's
 * gezel_id, never the app's primary key: the middleware knows owners by the
 * same key it was provisioned with, and enforces the monthly cap fail-closed
 * against it. Omit the owner and the call runs unmetered and uncapped.
 */
abstract class MiddlewareComputeClient
{
    abstract protected function timeout(): int;

    protected function request(?GezelOwner $owner, string $phase, ?int $timeout = null, string $source = 'platform'): PendingRequest
    {
        $url = rtrim((string) config('gezel.middleware.url'), '/');
        $token = (string) config('gezel.middleware.app_token');

        if ($url === '' || $token === '') {
            throw new RuntimeException('gezel.middleware.url or gezel.middleware.app_token is not configured.');
        }

        $http = Http::baseUrl($url)
            ->timeout($timeout ?? $this->timeout())
            ->withToken($token);

        $gezelId = $this->gezelId($owner);

        if ($gezelId !== null) {
            return $http->withHeaders([
                'X-Usage-User-Id' => $gezelId,
                'X-Usage-Source' => $source,
                'X-Usage-Phase' => $phase,
            ]);
        }

        // The fail-open below is deliberate, the silence is not. A caller that
        // passed an owner asked for a metered call and is not getting one: the
        // call still runs, bills nothing, counts against no cap, and every test
        // stays green. Warn here rather than in gezelId(), which recordUsage()
        // also calls — one line per dropped call, not two.
        if ($owner !== null) {
            Log::warning('Gezel compute call is unmetered: the owner has no gezel_id, so the middleware cannot meter or cap it.', [
                'owner_model' => $owner::class,
                'owner_key' => $owner instanceof Model ? $owner->getKey() : null,
                'phase' => $phase,
            ]);
        }

        return $http;
    }

    /**
     * Null for an owner that has no gezel_id yet: it was never provisioned, so
     * the middleware has nothing to meter the call against. Better unmetered
     * than metered to an id the middleware has never seen. A pure read —
     * request() warns on the null, once per call.
     */
    protected function gezelId(?GezelOwner $owner): ?string
    {
        if (! $owner instanceof Model) {
            return null;
        }

        $gezelId = $owner->getAttribute('gezel_id');

        return is_string($gezelId) && $gezelId !== '' ? $gezelId : null;
    }
}
