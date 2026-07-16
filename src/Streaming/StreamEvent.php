<?php

namespace Onomahq\Gezel\Streaming;

/**
 * One decoded SSE event. The constructor is private and fromWire() is the only
 * way to build one from gateway bytes, so the real parser and
 * Testing\FakeGezelStream cannot disagree about what a consumer receives:
 * `data` is always the whole decoded wire payload, `type` key included.
 */
final readonly class StreamEvent
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(
        public StreamEventType $type,
        public array $data,
    ) {}

    /**
     * Null for an unknown or absent type, which the gateway is free to add
     * without breaking an older consumer.
     *
     * @param  array<string, mixed>  $wire
     */
    public static function fromWire(array $wire): ?self
    {
        if (! isset($wire['type']) || ! is_string($wire['type'])) {
            return null;
        }

        $type = StreamEventType::tryFrom($wire['type']);

        return $type === null ? null : new self($type, $wire);
    }

    public static function error(string $message): self
    {
        return new self(StreamEventType::Error, ['type' => 'error', 'content' => $message]);
    }

    /**
     * The `content` field token, done, and error events carry.
     */
    public function content(): ?string
    {
        $content = $this->data['content'] ?? null;

        return is_scalar($content) ? (string) $content : null;
    }
}
