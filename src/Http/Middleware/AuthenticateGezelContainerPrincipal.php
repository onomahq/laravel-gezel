<?php

namespace Onomahq\Gezel\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Onomahq\Gezel\Auth\GezelPrincipal;
use Onomahq\Gezel\Contracts\PrincipalVerifier;
use Onomahq\Gezel\Http\GezelRefusal;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer auth for container→app callbacks (agent-messages). Resolves the
 * bearer to a {@see GezelPrincipal} and attaches it to the request
 * attributes, never `Auth::setUser()`, per Module 4. Refuses via
 * {@see GezelRefusal} on any failure, so a missing bearer and a rejected one
 * are indistinguishable from every other refusal on these routes.
 *
 * Implements the empty AuthenticatesRequests marker purely for middleware
 * priority: Laravel hoists ThrottleRequests above any lower-priority
 * middleware it finds (the api group's SubstituteBindings), and takes this
 * middleware with it unless it too is in the priority map. Without the marker
 * the limiter runs first, sees no principal, and keys every container into one
 * shared bucket, which Module 4 requires it not do.
 */
class AuthenticateGezelContainerPrincipal implements AuthenticatesRequests
{
    public function __construct(private readonly PrincipalVerifier $verifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if (! is_string($bearer) || $bearer === '') {
            return GezelRefusal::response();
        }

        $principal = $this->verifier->verify($bearer);

        if ($principal === null) {
            return GezelRefusal::response();
        }

        $request->attributes->set('principal', $principal);

        return $next($request);
    }
}
