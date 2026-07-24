<?php

namespace Onomahq\Gezel\Compute;

use Illuminate\Support\Facades\Log;
use Onomahq\Gezel\Contracts\GezelOwner;
use Throwable;

/**
 * Speech-to-text via the middleware's `/v1/transcribe` service. The middleware
 * holds the STT key; an app only relays audio bytes.
 *
 * Every failure answers null rather than throwing: a voice note whose
 * transcript never arrived should still upload and still reach the agent, which
 * can say it couldn't listen. A caller that needs the distinction reads the log.
 */
class TranscriptionClient extends MiddlewareComputeClient
{
    private const TIMEOUT = 60;

    /**
     * @return array{text: string, language: ?string}|null
     */
    public function transcribe(string $bytes, string $mime, ?GezelOwner $owner = null): ?array
    {
        if ($bytes === '') {
            return null;
        }

        try {
            $response = $this->request($owner, 'chat', source: 'agent')
                ->attach('file', $bytes, 'audio', ['Content-Type' => $mime])
                ->post('/v1/transcribe');
        } catch (Throwable $e) {
            Log::warning('Transcription request failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Transcription failed', ['status' => $response->status()]);

            return null;
        }

        $text = (string) ($response->json('text') ?? '');

        if (trim($text) === '') {
            return null;
        }

        $language = $response->json('language');

        return [
            'text' => $text,
            'language' => is_string($language) ? $language : null,
        ];
    }

    protected function timeout(): int
    {
        return self::TIMEOUT;
    }
}
