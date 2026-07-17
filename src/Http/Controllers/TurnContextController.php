<?php

namespace Onomahq\Gezel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Onomahq\Gezel\Contracts\TurnContextProvider;
use Onomahq\Gezel\Http\GezelRefusal;
use Onomahq\Gezel\Support\Owner;
use Onomahq\Gezel\Support\Viewing;

/**
 * The middleware calls this immediately before relaying a message from a
 * channel that never reaches the app (Telegram), so the agent opens the
 * turn grounded in the user's product state. Authed via the shared service
 * token, since no container is involved yet at relay time. No `Auth::setUser()`.
 */
class TurnContextController extends Controller
{
    public function __invoke(Request $request, TurnContextProvider $provider): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'string'],
            'viewing' => ['sometimes', 'array'],
            'viewing.kind' => ['required_with:viewing', 'string'],
            'viewing.name' => ['required_with:viewing', 'string'],
            'viewing.id' => ['sometimes', 'string'],
            'viewing.detail' => ['sometimes', 'string'],
        ]);

        // The middleware knows the owner by gezel_id, never by our own PK.
        $owner = Owner::findByGezelId($validated['user_id']);

        // The standard refusal body, not a turn_context: answering an unknown
        // gezel_id distinctly from a bad token turns this into an owner
        // enumeration oracle for anyone holding the service token. A 200 with
        // turn_context null stays reserved for a real owner with no context.
        if ($owner === null) {
            return GezelRefusal::response();
        }

        $viewing = isset($validated['viewing']) ? Viewing::fromArray($validated['viewing']) : null;

        return response()->json(['turn_context' => $provider->compose($owner, $viewing)]);
    }
}
