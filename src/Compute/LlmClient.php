<?php

namespace Onomahq\Gezel\Compute;

use Onomahq\Gezel\Contracts\ComputeUsageRecorder;
use Onomahq\Gezel\Contracts\GezelOwner;
use Onomahq\Gezel\Exceptions\UsageCapExceededException;
use Onomahq\Gezel\GezelStreamClient;
use RuntimeException;
use Throwable;

/**
 * Non-streaming chat completions through the middleware proxy. A chat *turn*
 * belongs to the container and streams over {@see GezelStreamClient};
 * this is the app's own server-side inference (classification, summarisation,
 * extraction) where the app, not the agent, is the caller.
 *
 * Timeout and retry count are the caller's to set, per call. The client holds
 * no table of which phases are slow or safe to repeat: only the caller knows
 * whether its prompt is deterministic enough that a second attempt returns the
 * same answer, and a shared package cannot carry one app's phase vocabulary.
 */
class LlmClient extends MiddlewareComputeClient
{
    private const RETRY_BASE_MS = 250;

    public function __construct(private readonly ComputeUsageRecorder $usageRecorder) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array{
     *     max_tokens?: int|null,
     *     temperature?: float|null,
     *     response_format?: array<string, mixed>|null,
     *     phase?: string|null,
     *     timeout?: int|null,
     *     retries?: int|null,
     *     provider?: string|null,
     *     usage_source?: string|null,
     *     ledger_source?: string|null,
     * }  $options
     * @return array{
     *     content: string,
     *     model: string,
     *     usage: array<string, mixed>,
     *     latency_ms: int,
     *     raw: array<string, mixed>,
     * }
     */
    public function chat(string $model, array $messages, array $options = [], ?GezelOwner $owner = null): array
    {
        $phase = (string) ($options['phase'] ?? '');
        $timeout = (int) ($options['timeout'] ?? config('gezel.timeout', 60));
        $maxAttempts = max(0, (int) ($options['retries'] ?? 0)) + 1;

        $payload = array_filter([
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $options['max_tokens'] ?? null,
            'temperature' => $options['temperature'] ?? null,
            'response_format' => $options['response_format'] ?? null,
            'stream' => false,
        ], static fn ($value): bool => $value !== null);

        $attempt = 0;
        $lastError = null;
        $startMs = (int) (microtime(true) * 1000);

        while ($attempt < $maxAttempts) {
            $attempt++;

            try {
                $response = $this->request($owner, $phase, $timeout, (string) ($options['usage_source'] ?? 'platform'))
                    ->acceptJson()
                    ->asJson()
                    ->post('/v1/chat/completions', $payload);
            } catch (Throwable $e) {
                // Transport failures (connection refused, timeout) are exactly
                // what a retry is for; hold the error and try again.
                $lastError = $e;
                $response = null;
            }

            if ($response !== null && $response->successful()) {
                $latency = (int) ((microtime(true) * 1000) - $startMs);
                $data = $response->json();
                $data = is_array($data) ? $data : [];

                $this->recordUsage(
                    $owner,
                    $phase,
                    (string) ($options['provider'] ?? $this->providerForModel($model)),
                    $model,
                    $data,
                    $latency,
                    (string) ($options['ledger_source'] ?? 'compute'),
                );

                return [
                    'content' => (string) ($data['choices'][0]['message']['content'] ?? ''),
                    'model' => (string) ($data['model'] ?? $model),
                    'usage' => is_array($data['usage'] ?? null) ? $data['usage'] : [],
                    'latency_ms' => $latency,
                    'raw' => $data,
                ];
            }

            // A cap rejection cannot succeed until the cap resets, so it escapes
            // the retry loop immediately. A transient provider 429 carries a
            // different error.type and keeps retrying below.
            if ($response !== null && $response->status() === 429) {
                $body = $response->json();

                if (is_array($body) && ($body['error']['type'] ?? null) === 'usage_limit_exceeded') {
                    throw UsageCapExceededException::fromResponseBody($body);
                }
            }

            if ($response !== null && $this->isPermanent($response->status())) {
                throw new RuntimeException(
                    "Middleware /v1/chat/completions failed: {$response->status()} {$response->body()}"
                );
            }

            if ($attempt < $maxAttempts) {
                usleep(self::RETRY_BASE_MS * 1000 * $attempt);
            } elseif ($response !== null) {
                throw new RuntimeException(
                    "Middleware /v1/chat/completions failed after {$attempt} attempts: {$response->status()} {$response->body()}"
                );
            }
        }

        throw new RuntimeException(
            'Middleware /v1/chat/completions failed without a response: '
            .($lastError?->getMessage() ?? 'unknown error')
        );
    }

    protected function timeout(): int
    {
        return (int) config('gezel.timeout', 60);
    }

    /**
     * 4xx other than 429: the request is malformed or unauthorized, and
     * repeating it verbatim cannot change the answer.
     */
    private function isPermanent(int $status): bool
    {
        return $status >= 400 && $status < 500 && $status !== 429;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function recordUsage(?GezelOwner $owner, string $phase, string $provider, string $requestedModel, array $data, int $latencyMs, string $ledgerSource): void
    {
        $gezelId = $this->gezelId($owner);

        if ($gezelId === null) {
            return;
        }

        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
        $input = (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
        $output = (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
        $cacheCreation = (int) ($usage['cache_creation_input_tokens'] ?? 0);
        $cacheRead = (int) ($usage['cache_read_input_tokens'] ?? 0);

        if (($input + $output + $cacheCreation + $cacheRead) === 0) {
            return;
        }

        $this->usageRecorder->record([
            'user_id' => $gezelId,
            'source' => $ledgerSource,
            'provider' => $provider,
            'model' => (string) ($data['model'] ?? $requestedModel),
            'phase' => $phase === '' ? null : $phase,
            'input_tokens' => $input,
            'output_tokens' => $output,
            'cache_creation_tokens' => $cacheCreation,
            'cache_read_tokens' => $cacheRead,
            'context' => ['latency_ms' => $latencyMs],
        ]);
    }

    private function providerForModel(string $model): string
    {
        if (str_contains($model, '/')) {
            return explode('/', $model, 2)[0];
        }

        return match (true) {
            str_starts_with($model, 'gpt-'),
            preg_match('/^o[1345](?:-|$)/', $model) === 1,
            str_starts_with($model, 'text-embedding-') => 'openai',
            str_starts_with($model, 'gemini-') => 'google',
            str_starts_with($model, 'claude-') => 'anthropic',
            default => 'unknown',
        };
    }
}
