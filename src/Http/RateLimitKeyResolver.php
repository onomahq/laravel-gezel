<?php

namespace Onomahq\Gezel\Http;

use Illuminate\Http\Request;
use Onomahq\Gezel\Auth\GezelPrincipal;
use Onomahq\Gezel\Support\Owner;

/**
 * Resolves the `gezel-internal` rate limiter's per-caller key. Never trusts
 * a raw request body field directly (Stagent's shipped limiter keys on
 * `$request->input('user_id', 'none')`, which lets a caller mint itself a
 * fresh bucket on every request by varying that field): a container-principal
 * request keys on the principal already resolved by upstream auth
 * middleware; a service-token request's `user_id` (a gezel_id) is only used
 * as a key once it resolves to a real owner row, and anything that doesn't
 * resolve collapses into one shared 'unresolved' bucket instead of getting
 * its own.
 */
final class RateLimitKeyResolver
{
    public function resolve(Request $request): string
    {
        $principal = $request->attributes->get('principal');

        if ($principal instanceof GezelPrincipal) {
            return $principal->gezelId;
        }

        $gezelId = $request->input('user_id');

        if (is_string($gezelId) && $gezelId !== '') {
            $owner = Owner::findByGezelId($gezelId);

            if ($owner !== null) {
                return $gezelId;
            }
        }

        return 'unresolved';
    }
}
