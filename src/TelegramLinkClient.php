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
        try {
            $response = $this->client()->post('/v1/connectors/telegram/links', [
                'user_id' => $gezelId,
            ]);
        } catch (ConnectionException) {
            return ['deep_link' => null, 'bot_username' => null, 'expires_at' => null];
        }

        if (! $response->successful()) {
            return ['deep_link' => null, 'bot_username' => null, 'expires_at' => null];
        }

        return [
            'deep_link' => $response->json('deep_link'),
            'bot_username' => $response->json('bot_username'),
            'expires_at' => $response->json('expires_at'),
        ];
    }

    /**
     * @return array{connected: bool, bot_username: ?string}
     */
    public function status(string $gezelId): array
    {
        try {
            $response = $this->client()->get("/v1/connectors/telegram/links/{$gezelId}");
        } catch (ConnectionException) {
            return ['connected' => false, 'bot_username' => null];
        }

        if (! $response->successful()) {
            return ['connected' => false, 'bot_username' => null];
        }

        $status = [
            'connected' => (bool) $response->json('connected'),
            'bot_username' => $response->json('bot_username'),
        ];

        Cache::put($this->cacheKey($gezelId), $status, now()->addDay());

        return $status;
    }

    /**
     * Telegram link state for turn-context composition: cached briefly so
     * composing a turn never adds a middleware roundtrip per message, and
     * null when the middleware cannot say.
     *
     * @return array{connected: bool, bot_username: ?string}|null
     */
    public function cachedStatus(string $gezelId): ?array
    {
        $cached = Cache::get($this->cacheKey($gezelId));

        if (is_array($cached)) {
            return $cached;
        }

        try {
            // Composing a turn must never stall on a slow middleware.
            $response = $this->client()
                ->connectTimeout(1)
                ->timeout(2)
                ->get("/v1/connectors/telegram/links/{$gezelId}");
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $status = [
            'connected' => (bool) $response->json('connected'),
            'bot_username' => $response->json('bot_username'),
        ];

        Cache::put($this->cacheKey($gezelId), $status, now()->addDay());

        return $status;
    }

    public function unlink(string $gezelId): void
    {
        try {
            $this->client()->delete("/v1/connectors/telegram/links/{$gezelId}");
        } catch (Throwable) {
            // Best-effort: the middleware owns the binding; nothing to roll back locally.
        }

        Cache::forget($this->cacheKey($gezelId));
    }

    /**
     * A relayed turn can only originate from a linked channel, so the
     * request itself proves the Telegram link without asking the middleware.
     */
    public function markLinked(string $gezelId): void
    {
        Cache::put(
            $this->cacheKey($gezelId),
            ['connected' => true, 'bot_username' => null],
            now()->addDay(),
        );
    }

    protected function cacheKey(string $gezelId): string
    {
        return sprintf('gezel:%s:telegram-status:%s', config('gezel.app_id'), $gezelId);
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl(config('gezel.middleware.url'))
            ->connectTimeout(5)
            ->timeout(config('gezel.timeout'))
            ->withToken(config('gezel.middleware.app_token'));
    }
}
