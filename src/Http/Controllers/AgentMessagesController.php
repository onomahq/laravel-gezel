<?php

namespace Onomahq\Gezel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Onomahq\Gezel\Auth\GezelPrincipal;
use Onomahq\Gezel\Contracts\AgentMessageHandler;
use Onomahq\Gezel\Contracts\OwnerMembershipVerifier;
use Onomahq\Gezel\Http\Middleware\AuthenticateGezelContainerPrincipal;
use Onomahq\Gezel\Support\Owner;

/**
 * The container's push channel for an unprompted agent message (end of a
 * chat turn, cron heartbeat, signal triage). Authed via
 * {@see AuthenticateGezelContainerPrincipal}
 * — the owner comes only from the resolved principal, never request input.
 */
class AgentMessagesController extends Controller
{
    public function __invoke(Request $request, OwnerMembershipVerifier $membershipVerifier, AgentMessageHandler $handler): JsonResponse
    {
        $request->validate([
            'message' => ['required', 'string'],
        ]);

        /** @var GezelPrincipal $principal */
        $principal = $request->attributes->get('principal');

        $owner = Owner::model()::query()->find($principal->ownerId);

        if ($owner === null) {
            abort(404);
        }

        if (! $membershipVerifier->verify($owner)) {
            abort(404);
        }

        $handler->handle($owner, $request->all());

        return response()->json(['status' => 'sent']);
    }
}
