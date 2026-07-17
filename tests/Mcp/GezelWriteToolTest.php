<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Onomahq\Gezel\Contracts\WritesGate;
use Onomahq\Gezel\Tests\Fixtures\GezelUser;
use Onomahq\Gezel\Tests\Fixtures\TestMcpServer;
use Onomahq\Gezel\Tests\Fixtures\TestWriteTool;

beforeEach(function () {
    migrateGezelOwnerTable(GezelUser::class);
});

afterEach(function () {
    Schema::dropIfExists('users');
    Auth::logout();
});

function disableWrites(): void
{
    app()->bind(WritesGate::class, fn () => new class implements WritesGate
    {
        public function writesEnabled(Model $owner): bool
        {
            return false;
        }
    });
}

it('registers when the default gate allows writes for the authenticated owner', function () {
    $owner = GezelUser::create(['name' => 'Ada']);
    Auth::login($owner);

    expect((new TestWriteTool)->shouldRegister())->toBeTrue();
});

it('hides from tools/list when no owner is authenticated', function () {
    expect((new TestWriteTool)->shouldRegister())->toBeFalse();
});

it('hides from tools/list when a bound gate disables writes', function () {
    $owner = GezelUser::create(['name' => 'Ada']);
    Auth::login($owner);

    disableWrites();

    expect((new TestWriteTool)->shouldRegister())->toBeFalse();
});

it('returns an MCP tool error from handle() when the gate disables writes, even if list-time filtering was bypassed', function () {
    $owner = GezelUser::create(['name' => 'Ada']);
    Auth::login($owner);

    disableWrites();

    $response = (new TestWriteTool)->handle();

    expect($response->isError())->toBeTrue();
    expect($response->content()->toArray())->toMatchArray(['text' => 'Making changes is disabled for this connection.']);
});

it('runs the tool normally when the gate allows writes', function () {
    $owner = GezelUser::create(['name' => 'Ada']);
    Auth::login($owner);

    $response = (new TestWriteTool)->handle();

    expect($response->isError())->toBeFalse();
    expect($response->content()->toArray())->toMatchArray(['text' => 'wrote it']);
});

it('is unreachable over the wire when the bound gate disables writes, via the stock CallTool method', function () {
    // The framework's own CallTool applies shouldRegister() to tools/call,
    // not only tools/list (ServerContext::tools() filters both the same
    // way), so a disabled tool 404s at the JSON-RPC layer here rather than
    // ever reaching handle(). writesDisabledResponse() only matters for a
    // host that registers a custom tools/call method bypassing that filter
    // (Stagent's own CallTool override does this; not packaged here).
    $owner = GezelUser::create(['name' => 'Ada']);
    disableWrites();

    TestMcpServer::actingAs($owner)
        ->tool(TestWriteTool::class)
        ->assertHasErrors(['not found']);
});

it('returns the tool result normally over the wire when writes are allowed', function () {
    $owner = GezelUser::create(['name' => 'Ada']);

    TestMcpServer::actingAs($owner)
        ->tool(TestWriteTool::class)
        ->assertOk()
        ->assertSee('wrote it');
});
