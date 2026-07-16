<?php

namespace Onomahq\Gezel;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Onomahq\Gezel\Contracts\StreamsGezelChat;
use Onomahq\Gezel\Support\DispatchesStreamCallbacks;
use Onomahq\Gezel\Support\SseEventParser;
use RuntimeException;
use Throwable;

/**
 * ext-curl SSE consumer for /v1/proxy/{gezel_id}/v1/channels/inbound/stream.
 * ext-curl over Guzzle: CURLOPT_XFERINFOFUNCTION fires even while the stream
 * is silent (model thinking, tool running), so a stop request is seen
 * without waiting for the next byte — Guzzle streams but can't abort
 * mid-turn. ext-curl over proc_open+curl-binary: no shell-out, no
 * escapeshellarg, no external process.
 */
class GezelStreamClient implements StreamsGezelChat
{
    use DispatchesStreamCallbacks;

    public function stream(
        string $gezelId,
        string $externalChatId,
        string $text,
        ?string $personaId = null,
        ?string $turnContext = null,
        ?callable $onToken = null,
        ?callable $onToolCall = null,
        ?callable $onToolResult = null,
        ?callable $onDone = null,
        ?callable $onError = null,
    ): void {
        $url = rtrim((string) config('gezel.middleware.url'), '/')
            ."/v1/proxy/{$gezelId}/v1/channels/inbound/stream";

        $stopKey = $this->stopKey($gezelId, $externalChatId);
        Cache::forget($stopKey);

        $parser = new SseEventParser;
        $stopped = false;

        $handle = curl_init();

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($this->buildEnvelope($externalChatId, $text, $personaId, $turnContext)),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.config('gezel.middleware.app_token'),
                'Content-Type: application/json',
                'Accept: text/event-stream',
            ],
            CURLOPT_TIMEOUT => (int) config('gezel.timeout'),
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use ($parser, $onToken, $onToolCall, $onToolResult, $onDone, $onError): int {
                foreach ($parser->push($chunk) as $event) {
                    $this->dispatchStreamEvent($event, $onToken, $onToolCall, $onToolResult, $onDone, $onError);
                }

                return strlen($chunk);
            },
            CURLOPT_NOPROGRESS => false,
            // Fires on every progress tick, i.e. constantly while tokens are
            // flowing — throttled to one cache read per second so an active
            // stream doesn't hammer the cache backend.
            CURLOPT_XFERINFOFUNCTION => function () use ($stopKey, &$stopped): int {
                static $lastStopCheck = 0.0;

                if (microtime(true) - $lastStopCheck < 1) {
                    return 0;
                }

                $lastStopCheck = microtime(true);

                if (Cache::get($stopKey)) {
                    $stopped = true;

                    return 1;
                }

                return 0;
            },
        ]);

        curl_exec($handle);

        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        curl_close($handle);

        if ($stopped) {
            return;
        }

        if ($errno !== 0) {
            throw new RuntimeException("Gezel stream failed: {$error}");
        }

        if ($status >= 400) {
            throw new RuntimeException("Gezel stream failed with status {$status}");
        }
    }

    public function requestStop(string $gezelId, string $externalChatId): void
    {
        Cache::put($this->stopKey($gezelId, $externalChatId), true, now()->addSeconds(150));

        try {
            Http::baseUrl(config('gezel.middleware.url'))
                ->timeout(5)
                ->withToken(config('gezel.middleware.app_token'))
                ->post("/v1/proxy/{$gezelId}/v1/channels/inbound/stop", [
                    'platform' => 'web',
                    'external_chat_id' => $externalChatId,
                ]);
        } catch (Throwable) {
            // Best-effort: the cache flag above is the mechanism the stream
            // loop actually polls; this call is a belt-and-suspenders nudge.
        }
    }

    /**
     * The InboundEnvelope the gateway accepts, richer than what any single
     * consumer sends today — the gateway already tolerates the extra fields.
     *
     * @return array<string, mixed>
     */
    public function buildEnvelope(string $externalChatId, string $text, ?string $personaId = null, ?string $turnContext = null): array
    {
        $envelope = [
            'tenant_id' => config('gezel.app_id'),
            'platform' => 'web',
            'external_chat_id' => $externalChatId,
            'text' => $text,
        ];

        if ($personaId !== null && $personaId !== '') {
            $envelope['persona_id'] = $personaId;
        }

        if ($turnContext !== null && $turnContext !== '') {
            $envelope['turn_context'] = $turnContext;
        }

        return $envelope;
    }

    public function stopKey(string $gezelId, string $externalChatId): string
    {
        return sprintf('gezel:%s:stop:%s:%s', config('gezel.app_id'), $gezelId, $externalChatId);
    }
}
