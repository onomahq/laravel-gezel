<?php

namespace Onomahq\Gezel;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class TelegramLinkClient
{
    /**
     * Mint a single-use Telegram deep link. Degrades gracefully if the
     * middleware is unreachable so the UI never hangs.
     *
     * @return array{deep_link: ?string, bot_username: ?string, expires_at: ?string}
     */
    public function link(string $gezelId): array
    {
        $empty = ['deep_link' => null, 'bot_username' => null, 'expires_at' => null];

        try {
            $response = $this->client()->post('/v1/connectors/telegram/links', [
                'user_id' => $gezelId,
            ]);
        } catch (ConnectionException) {
            return $empty;
        }

        if (! $response->successful()) {
            return $empty;
        }

        return [
            'deep_link' => $this->stringOrNull($response->json('deep_link')),
            'bot_username' => $this->stringOrNull($response->json('bot_username')),
            'expires_at' => $this->stringOrNull($response->json('expires_at')),
        ];
    }

    /**
     * @return array{connected: bool, bot_username: ?string}
     */
    public function status(string $gezelId): array
    {
        return $this->fetchStatus($gezelId) ?? ['connected' => false, 'bot_username' => null];
    }

    /**
     * Telegram link state for turn-context composition: served from cache so
     * composing a turn never adds a middleware roundtrip per message, and on a
     * miss with tighter timeouts, because composing a turn must never stall on
     * a slow middleware. Null when the middleware cannot say.
     *
     * @return array{connected: bool, bot_username: ?string}|null
     */
    public function cachedStatus(string $gezelId): ?array
    {
        $cached = Cache::get($this->cacheKey($gezelId));

        if (is_array($cached)) {
            /** @var array{connected: bool, bot_username: ?string} $cached */
            return $cached;
        }

        return $this->fetchStatus($gezelId, connectTimeout: 1, timeout: 2);
    }

    public function unlink(string $gezelId): void
    {
        try {
            $this->client()->delete($this->linkPath($gezelId));
        } catch (Throwable) {
            // Best-effort: the middleware owns the binding; nothing to roll back locally.
        }

        Cache::forget($this->cacheKey($gezelId));
    }

    /**
     * A relayed turn can only originate from a linked channel, so the request
     * itself proves the Telegram link without asking the middleware. Keeps any
     * bot_username already cached, which this call has no way to learn.
     */
    public function markLinked(string $gezelId): void
    {
        $cached = Cache::get($this->cacheKey($gezelId));
        $botUsername = is_array($cached) ? $this->stringOrNull($cached['bot_username'] ?? null) : null;

        $this->cacheStatus($gezelId, ['connected' => true, 'bot_username' => $botUsername]);
    }

    /**
     * @return array{connected: bool, bot_username: ?string}|null
     */
    protected function fetchStatus(string $gezelId, ?int $connectTimeout = null, ?int $timeout = null): ?array
    {
        $client = $this->client();

        if ($connectTimeout !== null) {
            $client->connectTimeout($connectTimeout);
        }

        if ($timeout !== null) {
            $client->timeout($timeout);
        }

        try {
            $response = $client->get($this->linkPath($gezelId));
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $status = [
            'connected' => (bool) $response->json('connected'),
            'bot_username' => $this->stringOrNull($response->json('bot_username')),
        ];

        $this->cacheStatus($gezelId, $status);

        return $status;
    }

    /**
     * @param  array{connected: bool, bot_username: ?string}  $status
     */
    protected function cacheStatus(string $gezelId, array $status): void
    {
        Cache::put($this->cacheKey($gezelId), $status, now()->addDay());
    }

    protected function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function cacheKey(string $gezelId): string
    {
        return sprintf('gezel:%s:telegram-status:%s', config('gezel.app_id'), $gezelId);
    }

    protected function linkPath(string $gezelId): string
    {
        return '/v1/connectors/telegram/links/'.rawurlencode($gezelId);
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl(config('gezel.middleware.url'))
            ->connectTimeout(5)
            ->timeout(config('gezel.timeout'))
            ->withToken(config('gezel.middleware.app_token'));
    }
}
