<?php

use Illuminate\Http\Request;
use Onomahq\Gezel\Http\Middleware\VerifyGezelServiceToken;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

it('404s when no config token is set', function () {
    config()->set('gezel.middleware.service_token', null);

    $request = Request::create('/x', 'POST');
    $request->headers->set('Authorization', 'Bearer whatever');

    (new VerifyGezelServiceToken)->handle($request, fn () => response('ok'));
})->throws(NotFoundHttpException::class);

it('404s on a wrong bearer', function () {
    config()->set('gezel.middleware.service_token', 'correct-token');

    $request = Request::create('/x', 'POST');
    $request->headers->set('Authorization', 'Bearer wrong-token');

    (new VerifyGezelServiceToken)->handle($request, fn () => response('ok'));
})->throws(NotFoundHttpException::class);

it('404s on a missing bearer', function () {
    config()->set('gezel.middleware.service_token', 'correct-token');

    $request = Request::create('/x', 'POST');

    (new VerifyGezelServiceToken)->handle($request, fn () => response('ok'));
})->throws(NotFoundHttpException::class);

it('passes through on a matching bearer', function () {
    config()->set('gezel.middleware.service_token', 'correct-token');

    $request = Request::create('/x', 'POST');
    $request->headers->set('Authorization', 'Bearer correct-token');

    $response = (new VerifyGezelServiceToken)->handle($request, fn () => response('ok'));

    expect($response->getContent())->toBe('ok');
});
