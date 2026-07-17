<?php

namespace Onomahq\Gezel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Onomahq\Gezel\Http\GezelRefusal;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the callbacks the Gezel middleware makes into this app with the
 * shared `services.gezel`-style secret. Refuses on any failure, including an
 * unset config token, and never with a 401 that discloses the route exists.
 */
class VerifyGezelServiceToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('gezel.middleware.service_token');

        if (! is_string($expected) || $expected === '') {
            return GezelRefusal::response();
        }

        if (! hash_equals($expected, (string) $request->bearerToken())) {
            return GezelRefusal::response();
        }

        return $next($request);
    }
}
