<?php

namespace Onomahq\Gezel\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Handles an unprompted agent message pushed from the container. `$owner` is
 * always the owner resolved from the authenticated container principal —
 * never from request input — so a naive implementation cannot be tricked
 * into acting on the wrong owner. Any target this handler looks up inside
 * `$payload` (a chat id, a session id, ...) must still be scoped to `$owner`;
 * the package only guarantees the owner itself is legitimate and current.
 */
interface AgentMessageHandler
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(Model $owner, array $payload): void;
}
