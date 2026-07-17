<?php

namespace Onomahq\Gezel\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Proves that the target an agent message names (a chat, a session) belongs to
 * the owner the container authenticated as. {@see AgentMessageHandler} looking
 * this up itself is the naive-handler hole Module 4 exists to close, so the
 * package controller calls this unconditionally before the handler and refuses
 * on false. A handler is never reached for a target this rejects.
 *
 * The package cannot know an app's chat schema, so it ships a default that
 * refuses any payload naming a target (see Defaults\DeniesUnverifiableTargets):
 * fail closed and loud, rather than let the package promise a check it never
 * performed. An app that routes agent messages to a target binds its own.
 */
interface TargetOwnershipVerifier
{
    /**
     * The payload keys that name a delivery target. The controller validates
     * exactly these (everything else is stripped before the handler sees it),
     * and the shipped default refuses when any is present.
     */
    public const TARGET_KEYS = ['chat_id', 'session_id', 'external_chat_id'];

    /**
     * @param  array<string, mixed>  $payload  Validated payload; only TARGET_KEYS name a target.
     */
    public function verify(Model $owner, array $payload): bool;
}
