<?php

namespace Onomahq\Gezel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Onomahq\Gezel\Auth\GezelPrincipal;
use Onomahq\Gezel\Contracts\PrincipalVerifier;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer auth for container→app callbacks (agent-messages). Resolves the
 * bearer to a {@see GezelPrincipal} and attaches it to
 * the request attributes — never `Auth::setUser()`, per Module 4. 404 on any
 * failure, matching the uniform-404 convention of every other internal
 * route.
 */
class AuthenticateGezelContainerPrincipal
{
    public function __construct(private readonly PrincipalVerifier $verifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (! is_string($bearer) || $bearer === '') {
            abort(404);
        }

        $principal = $this->verifier->verify($bearer);

        if ($principal === null) {
            abort(404);
        }

        $request->attributes->set('principal', $principal);

        return $next($request);
    }
}
