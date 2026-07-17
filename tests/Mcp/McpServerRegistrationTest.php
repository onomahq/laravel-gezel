<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Onomahq\Gezel\GezelServiceProvider;
use Onomahq\Gezel\Tests\Fixtures\TestMcpServer;

it('registers the MCP route when gezel.mcp.server is configured', function () {
    config()->set('gezel.mcp.server', TestMcpServer::class);
    config()->set('gezel.mcp.path', '/mcp');

    (new GezelServiceProvider($this->app))->packageBooted();

    $route = Route::getRoutes()->match(
        Request::create('/mcp', 'POST')
    );

    expect($route)->not->toBeNull();
});

it('applies the configured middleware to the MCP route', function () {
    config()->set('gezel.mcp.server', TestMcpServer::class);
    config()->set('gezel.mcp.path', '/mcp-middleware-test');
    config()->set('gezel.mcp.middleware', ['auth:sanctum']);

    (new GezelServiceProvider($this->app))->packageBooted();

    $route = Route::getRoutes()->match(
        Request::create('/mcp-middleware-test', 'POST')
    );

    expect($route->middleware())->toContain('auth:sanctum');
});

it('does not register a route when gezel.mcp.server is null', function () {
    config()->set('gezel.mcp.server', null);
    config()->set('gezel.mcp.path', '/mcp-unconfigured');

    (new GezelServiceProvider($this->app))->packageBooted();

    expect(
        Route::getRoutes()->getByAction(TestMcpServer::class) !== null
            || collect(Route::getRoutes())->contains(fn ($r) => $r->uri() === 'mcp-unconfigured')
    )->toBeFalse();
});

it('refuses a gezel.mcp.server that does not extend GezelMcpServer', function () {
    config()->set('gezel.mcp.server', stdClass::class);

    expect(fn () => (new GezelServiceProvider($this->app))->packageBooted())
        ->toThrow(RuntimeException::class, 'GezelMcpServer');
});

it('refuses a gezel.mcp.server class-string that does not exist', function () {
    config()->set('gezel.mcp.server', 'App\Mcp\DoesNotExist');

    expect(fn () => (new GezelServiceProvider($this->app))->packageBooted())
        ->toThrow(RuntimeException::class, 'GezelMcpServer');
});
