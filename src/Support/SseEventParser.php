<?php

namespace Onomahq\Gezel\Support;

/**
 * Incremental parser for the gateway's `data: {...}` SSE lines. Pure and
 * curl-independent: feed it raw bytes as they arrive (a full line, a partial
 * line, several lines at once — it buffers), get back the fully-decoded
 * events found so far.
 */
class SseEventParser
{
    protected string $buffer = '';

    /**
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    public function push(string $chunk): array
    {
        $this->buffer .= $chunk;
        $events = [];

        while (($pos = strpos($this->buffer, "\n")) !== false) {
            $line = trim(substr($this->buffer, 0, $pos));
            $this->buffer = substr($this->buffer, $pos + 1);

            if ($line === '' || ! str_starts_with($line, 'data: ')) {
                continue;
            }

            $data = substr($line, 6);

            if ($data === '[DONE]') {
                continue;
            }

            $decoded = json_decode($data, true);

            if (! is_array($decoded) || ! isset($decoded['type']) || ! is_string($decoded['type'])) {
                continue;
            }

            $events[] = ['type' => $decoded['type'], 'data' => $decoded];
        }

        return $events;
    }
}
