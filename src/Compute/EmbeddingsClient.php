<?php

namespace Onomahq\Gezel\Compute;

use Onomahq\Gezel\Contracts\ComputeUsageRecorder;
use Onomahq\Gezel\Contracts\GezelOwner;
use RuntimeException;

/**
 * Embeddings through the middleware's `/v1/embeddings` proxy — the same route,
 * model and dimensions the container's own embedder uses, so an app's vectors
 * and its agent-memory vectors share one embedding space. An app that embeds
 * with a different model or dimension count cannot compare the two.
 */
class EmbeddingsClient extends MiddlewareComputeClient
{
    /**
     * Sent explicitly rather than left to middleware-side injection, which is
     * version-dependent; the upstream 400s without a model.
     */
    public const MODEL = 'text-embedding-3-small';

    public const DIMENSIONS = 256;

    /**
     * text-embedding-3-small rejects inputs over 8191 tokens. ~20k chars is
     * roughly 5-7k tokens for dense text, so truncating here fails a caller's
     * oversized input late and cheaply rather than at the provider.
     */
    public const MAX_INPUT_CHARS = 20000;

    private const TIMEOUT = 30;

    public function __construct(private readonly ComputeUsageRecorder $usageRecorder) {}

    /**
     * @return list<float>
     */
    public function embed(string $text, ?GezelOwner $owner = null): array
    {
        return $this->embedMany([$text], $owner)[0];
    }

    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embedMany(array $texts, ?GezelOwner $owner = null, string $ledgerSource = 'embedding'): array
    {
        if ($texts === []) {
            return [];
        }

        $response = $this->request($owner, 'embedding')
            ->acceptJson()
            ->asJson()
            ->post('/v1/embeddings', [
                'model' => self::MODEL,
                'input' => array_map(
                    static fn (string $text): string => mb_substr($text, 0, self::MAX_INPUT_CHARS),
                    $texts,
                ),
                'dimensions' => self::DIMENSIONS,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "Middleware /v1/embeddings failed: {$response->status()} {$response->body()}"
            );
        }

        $data = $response->json('data');

        if (! is_array($data) || count($data) !== count($texts)) {
            throw new RuntimeException('Middleware /v1/embeddings returned an unexpected payload.');
        }

        $this->recordUsage($owner, $response->json('usage'), (string) $response->json('model', self::MODEL), count($texts), $ledgerSource);

        usort($data, static fn (array $a, array $b): int => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

        return array_map(static function (array $item): array {
            $embedding = $item['embedding'] ?? null;

            if (! is_array($embedding) || $embedding === []) {
                throw new RuntimeException('Middleware /v1/embeddings item is missing its embedding.');
            }

            return array_map(static fn ($value): float => (float) $value, array_values($embedding));
        }, $data);
    }

    protected function timeout(): int
    {
        return self::TIMEOUT;
    }

    private function recordUsage(?GezelOwner $owner, mixed $usage, string $model, int $inputCount, string $ledgerSource): void
    {
        $gezelId = $this->gezelId($owner);

        if ($gezelId === null) {
            return;
        }

        $usage = is_array($usage) ? $usage : [];
        $input = (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);

        if ($input <= 0) {
            return;
        }

        $this->usageRecorder->record([
            'user_id' => $gezelId,
            'source' => $ledgerSource,
            'provider' => 'openai',
            'model' => $model,
            'input_tokens' => $input,
            'output_tokens' => 0,
            'context' => ['input_count' => $inputCount, 'dimensions' => self::DIMENSIONS],
        ]);
    }
}
