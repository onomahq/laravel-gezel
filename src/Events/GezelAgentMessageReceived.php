<?php

namespace Onomahq\Gezel\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

final class GezelAgentMessageReceived
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly Model $owner,
        public readonly array $payload,
    ) {}
}
