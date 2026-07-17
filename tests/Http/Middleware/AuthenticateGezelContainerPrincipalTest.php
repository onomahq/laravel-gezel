<?php

use Illuminate\Http\Request;
use Onomahq\Gezel\Auth\GezelPrincipal;
use Onomahq\Gezel\Contracts\PrincipalVerifier;
use Onomahq\Gezel\Http\Middleware\AuthenticateGezelContainerPrincipal;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

it('404s when there is no bearer at all', function () {
    $request = Request::create('/x', 'POST');

    (new AuthenticateGezelContainerPrincipal(fakeVerifier(null)))->handle($request, fn () => response('ok'));
})->throws(NotFoundHttpException::class);

it('404s when the verifier rejects the bearer', function () {
    $request = Request::create('/x', 'POST');
    $request->headers->set('Authorization', 'Bearer whatever');

    (new AuthenticateGezelContainerPrincipal(fakeVerifier(null)))->handle($request, fn () => response('ok'));
})->throws(NotFoundHttpException::class);

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
