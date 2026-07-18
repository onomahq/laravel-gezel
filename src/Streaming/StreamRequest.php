<?php

namespace Onomahq\Gezel\Streaming;

/**
 * One chat turn's request, and the InboundEnvelope it becomes on the wire.
 * The envelope is richer than what any single consumer sends today, which the
 * gateway already tolerates.
 */
final readonly class StreamRequest
{
    /**
     * @param  list<array<string, mixed>>  $attachments  Gateway AttachmentPayload
     *                                                   entries (kind, mime, filename,
     *                                                   data_b64, transcript, note).
     */
    public function __construct(
        public string $gezelId,
        public string $externalChatId,
        public string $text,
        public ?string $personaId = null,
        public ?string $turnContext = null,
        public array $attachments = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toEnvelope(): array
    {
        $envelope = [
            'tenant_id' => config('gezel.app_id'),
            'platform' => 'web',
            'external_chat_id' => $this->externalChatId,
            'text' => $this->text,
        ];

        if ($this->personaId !== null && $this->personaId !== '') {
            $envelope['persona_id'] = $this->personaId;
        }

        if ($this->turnContext !== null && $this->turnContext !== '') {
            $envelope['turn_context'] = $this->turnContext;
        }

        if ($this->attachments !== []) {
            $envelope['attachments'] = $this->attachments;
        }

        return $envelope;
    }
}
