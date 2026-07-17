<?php

namespace Onomahq\Gezel\Http;

use Illuminate\Http\JsonResponse;

/**
 * The one place the refusal answer lives. Every way an internal route can say
 * no (missing bearer, wrong service token, unparseable payload, unknown owner,
 * rejected membership, unverifiable target, expired principal) answers with
 * this exact status and body, so a prober cannot tell them apart, and a
 * failure path added later cannot drift into its own recognisable shape.
 */
final class GezelRefusal
{
    public static function response(): JsonResponse
    {
        return response()->json(['error' => 'not found'], 404);
    }
}
