<?php

use Illuminate\Http\Request;
use Onomahq\Gezel\Auth\GezelPrincipal;
use Onomahq\Gezel\Contracts\PrincipalVerifier;
use Onomahq\Gezel\Http\Middleware\AuthenticateGezelContainerPrincipal;

function fakeVerifier(?GezelPrincipal $result): PrincipalVerifier
{
    return new class($result) implements PrincipalVerifier
    {
        public function __construct(private readonly ?GezelPrincipal $result) {}

        public function verify(string $bearer): ?GezelPrincipal
        {
            return $this->result;
        }
    };
}

it('refuses when there is no bearer at all, without reaching the route', function () {
    $request = Request::create('/x', 'POST');
    $reached = false;

    $response = (new AuthenticateGezelContainerPrincipal(fakeVerifier(null)))
        ->handle($request, function () use (&$reached) {
            $reached = true;

            return response('ok');
        });

    expect($response->getStatusCode())->toBe(404);
    expect($response->getData(true))->toBe(['error' => 'not found']);
    expect($reached)->toBeFalse();
});

it('refuses when the verifier rejects the bearer, with the same answer as no bearer', function () {
    $request = Request::create('/x', 'POST');
    $request->headers->set('Authorization', 'Bearer whatever');

    $response = (new AuthenticateGezelContainerPrincipal(fakeVerifier(null)))
        ->handle($request, fn () => response('ok'));

    expect($response->getStatusCode())->toBe(404);
    expect($response->getData(true))->toBe(['error' => 'not found']);
});

it('attaches the resolved principal to request attributes and passes through', function () {
    $principal = new GezelPrincipal(
        ownerId: '1',
        gezelId: 'gezel-1',
        principalId: 'token-1',
        expiresAt: null,
        scopes: ['*'],
    );

    $request = Request::create('/x', 'POST');
    $request->headers->set('Authorization', 'Bearer whatever');

    $captured = null;

    (new AuthenticateGezelContainerPrincipal(fakeVerifier($principal)))->handle($request, function ($req) use (&$captured) {
        $captured = $req->attributes->get('principal');

        return response('ok');
    });

    expect($captured)->toBe($principal);
});
