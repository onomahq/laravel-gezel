<?php

namespace Onomahq\Gezel;

use CurlHandle;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Onomahq\Gezel\Contracts\StreamsGezelChat;
use Onomahq\Gezel\Streaming\StreamEvent;
use Onomahq\Gezel\Streaming\StreamEventType;
use Onomahq\Gezel\Streaming\StreamOutcome;
use Onomahq\Gezel\Streaming\StreamRequest;
use Onomahq\Gezel\Support\SseEventParser;
use Throwable;

/**
 * ext-curl SSE consumer for /v1/proxy/{gezel_id}/v1/channels/inbound/stream.
 * ext-curl over Guzzle: CURLOPT_XFERINFOFUNCTION fires even while the stream
 * is silent (model thinking, tool running), so a stop request is seen without
 * waiting for the next byte, where Guzzle streams but cannot abort mid-turn.
 * ext-curl over proc_open plus the curl binary: no shell-out, no
 * escapeshellarg, no external process.
 */
class GezelStreamClient implements StreamsGezelChat
{
    /**
     * XFERINFO fires constantly while tokens flow. One cache read per second
     * is enough to notice a stop without hammering the cache backend.
     */
    protected const STOP_POLL_SECONDS = 1;

    public function stream(StreamRequest $request, ?callable $onEvent = null): StreamOutcome
    {
        $stopKey = $this->stopKey($request->gezelId, $request->externalChatId);
        Cache::forget($stopKey);

        $parser = new SseEventParser;
        $stopped = false;
        $errored = false;
        $lastStopCheck = 0.0;

        $onWrite = function (CurlHandle $handle, string $chunk) use ($parser, $onEvent, &$errored): int {
            foreach ($parser->push($chunk) as $event) {
                $errored = $errored || $event->type === StreamEventType::Error;

                if ($onEvent !== null) {
                    $onEvent($event);
                }
            }

            return strlen($chunk);
        };

        $onProgress = function () use ($stopKey, &$stopped, &$lastStopCheck): int {
            if (microtime(true) - $lastStopCheck < self::STOP_POLL_SECONDS) {
                return 0;
            }

            $lastStopCheck = microtime(true);

            if (Cache::get($stopKey)) {
                $stopped = true;

                return 1;
            }

            return 0;
        };

        $handle = curl_init();
        curl_setopt_array($handle, $this->curlOptions($request, $onWrite, $onProgress));

        curl_exec($handle);

        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        // No curl_close(): handles are objects since PHP 8.0 and the function
        // is deprecated on PHP 8.5; the handle frees itself when it drops out
        // of scope.
        unset($handle);

        // Checked before $errno: aborting from the progress callback is itself
        // a cURL error (CURLE_ABORTED_BY_CALLBACK), and a stop is not a failure.
        if ($stopped) {
            return StreamOutcome::forTurn(stopped: true, errored: $errored);
        }

        if ($errno !== 0) {
            return $this->fail($onEvent, "Gezel stream failed: {$error}");
        }

        if ($status >= 400) {
            return $this->fail($onEvent, "Gezel stream failed with status {$status}");
        }

        return StreamOutcome::forTurn(stopped: false, errored: $errored);
    }

    public function requestStop(string $gezelId, string $externalChatId): void
    {
        // The flag has to outlive the longest a turn can run, or a stop set
        // early in a slow turn expires before the loop next polls for it.
        Cache::put(
            $this->stopKey($gezelId, $externalChatId),
            true,
            now()->addSeconds($this->maxDuration()),
        );

        try {
            Http::baseUrl(config('gezel.middleware.url'))
                ->timeout(5)
                ->withToken(config('gezel.middleware.app_token'))
                ->post($this->proxyPath($gezelId).'/v1/channels/inbound/stop', [
                    'platform' => 'web',
                    'external_chat_id' => $externalChatId,
                ]);
        } catch (Throwable) {
            // Best-effort: the cache flag above is the mechanism the stream
            // loop actually polls; this call is a belt-and-suspenders nudge.
        }
    }

    /**
     * @param  callable(CurlHandle, string): int  $onWrite
     * @param  callable(): int  $onProgress
     * @return array<int, mixed>
     */
    public function curlOptions(StreamRequest $request, callable $onWrite, callable $onProgress): array
    {
        return [
            CURLOPT_URL => $this->streamUrl($request->gezelId),
            CURLOPT_POST => true,
            // INVALID_UTF8_SUBSTITUTE: an attachment filename with broken UTF-8
            // must not collapse the whole envelope to `false` (an empty body).
            CURLOPT_POSTFIELDS => (string) json_encode($request->toEnvelope(), JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.config('gezel.middleware.app_token'),
                'Content-Type: application/json',
                'Accept: text/event-stream',
            ],
            CURLOPT_CONNECTTIMEOUT => (int) config('gezel.stream.connect_timeout', 10),
            // A turn that runs tools legitimately outruns the unary
            // gezel.timeout, so the budget here is a generous runaway backstop
            // plus a low-speed window that only fires while nothing arrives.
            CURLOPT_TIMEOUT => $this->maxDuration(),
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => (int) config('gezel.stream.idle_timeout', 120),
            CURLOPT_WRITEFUNCTION => $onWrite,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => $onProgress,
        ];
    }

    public function stopKey(string $gezelId, string $externalChatId): string
    {
        return sprintf('gezel:%s:stop:%s:%s', config('gezel.app_id'), $gezelId, $externalChatId);
    }

    protected function fail(?callable $onEvent, string $message): StreamOutcome
    {
        if ($onEvent !== null) {
            $onEvent(StreamEvent::error($message));
        }

        return StreamOutcome::Failed;
    }

    protected function streamUrl(string $gezelId): string
    {
        return rtrim((string) config('gezel.middleware.url'), '/')
            .$this->proxyPath($gezelId)
            .'/v1/channels/inbound/stream';
    }

    protected function proxyPath(string $gezelId): string
    {
        return '/v1/proxy/'.rawurlencode($gezelId);
    }

    protected function maxDuration(): int
    {
        return (int) config('gezel.stream.max_duration', 600);
    }
}
