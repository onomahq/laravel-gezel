<?php

namespace Onomahq\Gezel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Onomahq\Gezel\Auth\PrincipalGate;
use Onomahq\Gezel\Contracts\PrincipalVerifier;

/**
 * The middleware calls this to resolve a container's bearer (presented over
 * its WebSocket) to the owner it was minted for. Authed via the shared
 * service token. Delegates to the bound PrincipalVerifier, which routes
 * every candidate through {@see PrincipalGate} before
 * this controller ever sees it — the response only restates what the gate
 * already re-asserted, per APP-CONTRACT §2c.
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
            return response()->json(['error' => 'principal not found'], 404);
        }

        return response()->json([
            'principal_id' => $principal->principalId,
            'user_id' => $principal->gezelId,
            'kind' => $principal->kind->value,
            'status' => $principal->status->value,
            'scopes' => $principal->scopes,
        ]);
    }
}
