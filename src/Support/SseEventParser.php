<?php

namespace Onomahq\Gezel\Support;

use Onomahq\Gezel\Streaming\StreamEvent;

/**
 * Incremental parser for the gateway's `data: {...}` SSE lines. Pure and
 * curl-independent: feed it raw bytes as they arrive (a full line, a partial
 * line, several lines at once, it buffers), get back the events found so far.
 */
class SseEventParser
{
    protected string $buffer = '';

    /**
     * @return list<StreamEvent>
     */
    public function push(string $chunk): array
    {
        $this->buffer .= $chunk;
        $events = [];

        while (($pos = strpos($this->buffer, "\n")) !== false) {
            $line = trim(substr($this->buffer, 0, $pos));
            $this->buffer = substr($this->buffer, $pos + 1);

            if (! str_starts_with($line, 'data:')) {
                continue;
            }

            // The space after `data:` is optional per the SSE spec.
            $data = ltrim(substr($line, 5));

            if ($data === '' || $data === '[DONE]') {
                continue;
            }

            /** @var mixed $decoded */
            $decoded = json_decode($data, true);

            if (! is_array($decoded)) {
                continue;
            }

            /** @var array<string, mixed> $decoded */
            $event = StreamEvent::fromWire($decoded);

            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }
}
