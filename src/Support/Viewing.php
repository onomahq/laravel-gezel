<?php

namespace Onomahq\Gezel\Support;

use Onomahq\Gezel\Contracts\TurnContextProvider;

/**
 * The record a user has open, passed into {@see TurnContextProvider}
 * so the composed context can ground the agent in what the user is looking
 * at. Route-to-record resolution stays app-side (Stagent's
 * `AssistantViewingResolver` is the reference).
 */
final readonly class Viewing
{
    public function __construct(
        public string $kind,
        public string $name,
        public ?string $id = null,
        public ?string $detail = null,
    ) {}

    /**
     * @param  array{kind: string, name: string, id?: string|null, detail?: string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            kind: $data['kind'],
            name: $data['name'],
            id: $data['id'] ?? null,
            detail: $data['detail'] ?? null,
        );
    }
}
