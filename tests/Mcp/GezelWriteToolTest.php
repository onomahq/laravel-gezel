<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Onomahq\Gezel\Contracts\WritesGate;
use Onomahq\Gezel\Tests\Fixtures\GezelUser;
use Onomahq\Gezel\Tests\Fixtures\TestWriteTool;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    migrateGezelOwnerTable(GezelUser::class);
});

afterEach(function () {
    Schema::dropIfExists('users');
    Auth::logout();
});

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

    app()->bind(WritesGate::class, fn () => new class implements WritesGate
    {
        public function writesEnabled(Model $owner): bool
        {
            return false;
        }
    });

    expect((new TestWriteTool)->shouldRegister())->toBeFalse();
});

it('re-checks the gate in ensureWritesEnabled even if list-time filtering was bypassed', function () {
    $owner = GezelUser::create(['name' => 'Ada']);
    Auth::login($owner);

    app()->bind(WritesGate::class, fn () => new class implements WritesGate
    {
        public function writesEnabled(Model $owner): bool
        {
            return false;
        }
    });

    expect(fn () => (new TestWriteTool)->callEnsureWritesEnabled())
        ->toThrow(HttpException::class);
});

it('lets ensureWritesEnabled pass when the gate allows it', function () {
    $owner = GezelUser::create(['name' => 'Ada']);
    Auth::login($owner);

    (new TestWriteTool)->callEnsureWritesEnabled();

    expect(true)->toBeTrue();
});
