<?php

namespace Onomahq\Gezel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Onomahq\Gezel\Usage\UsageRecorder;

/**
 * The middleware's usage-ledger callback. No $request->validate() here, ever:
 * the middleware dead-letters an event permanently on any 4xx except 408/429,
 * and the package maps a ValidationException on gezel.* routes to a 404 — so
 * one over-strict rule would silently destroy billing data. The recorder
 * coerces whatever arrived into a row; anything unpersistable is acknowledged
 * and dropped rather than bounced into the dead-letter queue.
 */
class UsageController extends Controller
{
    public function __invoke(Request $request, UsageRecorder $recorder): JsonResponse
    {
        $event = $request->json()->all();

        if ($event === []) {
            return response()->json(['status' => 'ignored']);
        }

        $recorder->record($event);

        return response()->json(['status' => 'recorded']);
    }
}
