<?php

namespace Onomahq\Gezel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the callbacks the Gezel middleware makes into this app with the
 * shared `services.gezel`-style secret. 404 on any failure, including an
 * unset config token — never a 401 that discloses the route exists.
 */
class VerifyGezelServiceToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('gezel.middleware.service_token');

        if (! is_string($expected) || $expected === '') {
            abort(404);
        }

        if (! hash_equals($expected, (string) $request->bearerToken())) {
            abort(404);
        }

        return $next($request);
    }
}
