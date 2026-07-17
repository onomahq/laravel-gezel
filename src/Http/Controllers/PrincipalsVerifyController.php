<?php

namespace Onomahq\Gezel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Onomahq\Gezel\Auth\GezelPrincipal;
use Onomahq\Gezel\Auth\PrincipalGate;
use Onomahq\Gezel\Contracts\PrincipalVerifier;
use Onomahq\Gezel\Http\GezelRefusal;

/**
 * The middleware calls this to resolve a container's bearer (presented over
 * its WebSocket) to the owner it was minted for. Authed via the shared service
 * token. Answers the APP-CONTRACT §2c shape.
 *
 * The shipped drivers route every candidate through {@see PrincipalGate},
 * which re-asserts these invariants already. A bound gezel.auth.driver is an
 * app's own class, though, and can return a {@see GezelPrincipal} the gate
 * never saw, so this controller re-asserts expiry itself rather than answer
 * `status: active` on a driver's say-so. `kind` and `status` need no re-assert:
 * they are fixed values on GezelPrincipal that no caller can set.
 */
class PrincipalsVerifyController extends Controller
{
    public function __invoke(Request $request, PrincipalVerifier $verifier): JsonResponse
    {
        $validated = $request->validate([
            'bearer' => ['required', 'string'],
        ]);

        $principal = $verifier->verify($validated['bearer']);

        if ($principal === null) {
            return GezelRefusal::response();
        }

        if ($principal->expiresAt !== null && $principal->expiresAt->isPast()) {
            return GezelRefusal::response();
        }

        return response()->json([
            'principal_id' => $principal->principalId,
            'user_id' => $principal->gezelId,
            'kind' => $principal->kind,
            'status' => $principal->status,
            'scopes' => $principal->scopes,
        ]);
    }
}
