<?php

namespace Onomahq\Gezel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Onomahq\Gezel\Auth\GezelPrincipal;
use Onomahq\Gezel\Contracts\AgentMessageHandler;
use Onomahq\Gezel\Contracts\OwnerMembershipVerifier;
use Onomahq\Gezel\Contracts\TargetOwnershipVerifier;
use Onomahq\Gezel\Http\GezelRefusal;
use Onomahq\Gezel\Http\Middleware\AuthenticateGezelContainerPrincipal;
use Onomahq\Gezel\Support\Owner;

/**
 * The container's push channel for an unprompted agent message (end of a chat
 * turn, cron heartbeat, signal triage). Authed via
 * {@see AuthenticateGezelContainerPrincipal}, so the owner comes only from the
 * resolved principal, never request input.
 *
 * Both checks below run here rather than in the handler, and run
 * unconditionally, so a handler cannot skip a check it never knew about: the
 * owner must still be current ({@see OwnerMembershipVerifier}), and any target
 * the payload names must belong to that owner
 * ({@see TargetOwnershipVerifier}).
 */
class AgentMessagesController extends Controller
{
    public function __invoke(
        Request $request,
        OwnerMembershipVerifier $membershipVerifier,
        TargetOwnershipVerifier $targetVerifier,
        AgentMessageHandler $handler,
    ): JsonResponse {
        // A closed schema, not $request->all(): the handler is the one thing
        // that acts on this payload, so nothing the package has not validated
        // and the target verifier has not seen should reach it.
        $payload = $request->validate([
            'message' => ['required', 'string'],
            'chat_id' => ['sometimes', 'string'],
            'session_id' => ['sometimes', 'string'],
            'external_chat_id' => ['sometimes', 'string'],
        ]);

        /** @var GezelPrincipal $principal */
        $principal = $request->attributes->get('principal');

        $owner = Owner::model()::query()->find($principal->ownerId);

        if ($owner === null) {
            return GezelRefusal::response();
        }

        if (! $membershipVerifier->verify($owner)) {
            return GezelRefusal::response();
        }

        if (! $targetVerifier->verify($owner, $payload)) {
            return GezelRefusal::response();
        }

        $handler->handle($owner, $payload);

        return response()->json(['status' => 'sent']);
    }
}
