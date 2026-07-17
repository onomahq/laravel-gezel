<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Onomahq\Gezel\ContainerInfo;
use Onomahq\Gezel\Exceptions\ContainerLifecycleDisabledException;
use Onomahq\Gezel\GezelOrchestrator;
use Onomahq\Gezel\HealthStatus;

beforeEach(function () {
    config()->set('gezel.middleware.url', 'http://middleware.test');
    config()->set('gezel.middleware.app_token', 'app-token-123');
});

it('provisions a container with the app_token and returns ContainerInfo', function () {
    Http::fake([
        'middleware.test/v1/containers/gezel-1/provision' => Http::response([
            'container_id' => 'c-abc',
            'status' => 'provisioned',
        ], 200),
    ]);

    $info = (new GezelOrchestrator)->provision('gezel-1', 'container-token');

    expect($info)->toBeInstanceOf(ContainerInfo::class);
    expect($info->containerId)->toBe('c-abc');
    expect($info->status)->toBe('provisioned');

    Http::assertSent(function ($request) {
        return $request->url() === 'http://middleware.test/v1/containers/gezel-1/provision'
            && $request->hasHeader('Authorization', 'Bearer app-token-123')
            && $request['container_token'] === 'container-token';
    });
});

it('recreates a container', function () {
    Http::fake([
        'middleware.test/v1/containers/gezel-1/recreate' => Http::response([
            'container_id' => 'c-abc',
            'status' => 'provisioned',
        ], 200),
    ]);

    expect((new GezelOrchestrator)->recreate('gezel-1', 'new-token')->containerId)->toBe('c-abc');
});

it('url-encodes the gezel id in container paths', function () {
    Http::fake(['middleware.test/*' => Http::response([], 200)]);

    (new GezelOrchestrator)->restart('gezel/../evil');

    Http::assertSent(fn ($request) => $request->url() === 'http://middleware.test/v1/containers/gezel%2F..%2Fevil/restart');
});

it('throws ContainerLifecycleDisabledException on a 501 response', function () {
    Http::fake([
        'middleware.test/v1/containers/gezel-1/provision' => Http::response('Docker not running', 501),
    ]);

    expect(fn () => (new GezelOrchestrator)->provision('gezel-1', 'token'))
        ->toThrow(ContainerLifecycleDisabledException::class);
});

it('throws a RequestException carrying the failed response', function () {
    Http::fake([
        'middleware.test/v1/containers/gezel-1/provision' => Http::response(['error' => 'boom'], 500),
    ]);

    expect(fn () => (new GezelOrchestrator)->provision('gezel-1', 'token'))
        ->toThrow(RequestException::class);

    try {
        (new GezelOrchestrator)->provision('gezel-1', 'token');
    } catch (RequestException $e) {
        expect($e->response->json('error'))->toBe('boom');
    }
});

it('throws when a successful provision returns no container_id', function () {
    Http::fake([
        'middleware.test/v1/containers/gezel-1/provision' => Http::response(['status' => 'provisioned'], 200),
    ]);

    expect(fn () => (new GezelOrchestrator)->provision('gezel-1', 'token'))
        ->toThrow(RuntimeException::class, 'returned no container_id');
});

it('deprovisions a container', function () {
    Http::fake([
        'middleware.test/v1/containers/gezel-1' => Http::response([], 200),
    ]);

    (new GezelOrchestrator)->deprovision('gezel-1');

    Http::assertSent(fn ($request) => $request->method() === 'DELETE');
});

it('throws when deprovision or restart fails', function (string $method) {
    Http::fake(['middleware.test/*' => Http::response([], 500)]);

    expect(fn () => (new GezelOrchestrator)->{$method}('gezel-1'))->toThrow(RequestException::class);
})->with(['deprovision', 'restart']);

it('restarts a container', function () {
    Http::fake([
        'middleware.test/v1/containers/gezel-1/restart' => Http::response([], 200),
    ]);

    (new GezelOrchestrator)->restart('gezel-1');

    Http::assertSentCount(1);
});

it('reports healthy from a successful health check', function () {
    Http::fake([
        'middleware.test/v1/containers/gezel-1/health' => Http::response(['uptime_seconds' => 42], 200),
    ]);

    $status = (new GezelOrchestrator)->healthCheck('gezel-1');

    expect($status)->toBeInstanceOf(HealthStatus::class);
    expect($status->healthy)->toBeTrue();
    expect($status->uptimeSeconds)->toBe(42);
});

it('tolerates a non-integer uptime instead of reporting a php error as unhealthy', function () {
    Http::fake([
        'middleware.test/v1/containers/gezel-1/health' => Http::response(['uptime_seconds' => '42'], 200),
    ]);

    $status = (new GezelOrchestrator)->healthCheck('gezel-1');

    expect($status->healthy)->toBeTrue();
    expect($status->uptimeSeconds)->toBe(42);
});

it('reports unhealthy without throwing when the health check fails', function () {
    Http::fake([
        'middleware.test/v1/containers/gezel-1/health' => Http::response([], 500),
    ]);

    $status = (new GezelOrchestrator)->healthCheck('gezel-1');

    expect($status->healthy)->toBeFalse();
    expect($status->error)->toBe('Health check returned 500');
});

it('reports unhealthy when the middleware is unreachable', function () {
    Http::fake(fn () => throw new ConnectionException('unreachable'));

    $status = (new GezelOrchestrator)->healthCheck('gezel-1');

    expect($status->healthy)->toBeFalse();
    expect($status->error)->toBe('unreachable');
});

it('writes config with the version and payload shape', function () {
    Http::fake([
        'middleware.test/v1/containers/gezel-1/config' => Http::response([], 200),
    ]);

    (new GezelOrchestrator)->writeConfig('gezel-1', ['persona' => 'default'], 3);

    Http::assertSent(fn ($request) => $request['version'] === 3 && $request['payload'] === ['persona' => 'default']);
});

it('throws when writeConfig fails', function () {
    Http::fake(['middleware.test/*' => Http::response([], 500)]);

    expect(fn () => (new GezelOrchestrator)->writeConfig('gezel-1', []))->toThrow(RequestException::class);
});
