<?php

use Illuminate\Http\Request;
use Onomahq\Gezel\Http\Middleware\VerifyGezelServiceToken;

function refuseServiceToken(?string $configured, ?string $sent): array
{
    config()->set('gezel.middleware.service_token', $configured);

    $request = Request::create('/x', 'POST');

    if ($sent !== null) {
        $request->headers->set('Authorization', "Bearer {$sent}");
    }

    $reached = false;

    $response = (new VerifyGezelServiceToken)->handle($request, function () use (&$reached) {
        $reached = true;

        return response('ok');
    });

    return [$response, $reached];
}

it('refuses identically whether the token is unset, wrong, or missing', function (?string $configured, ?string $sent) {
    [$response, $reached] = refuseServiceToken($configured, $sent);

    expect($response->getStatusCode())->toBe(404);
    expect($response->getData(true))->toBe(['error' => 'not found']);
    expect($reached)->toBeFalse();
})->with([
    'no config token set' => [null, 'whatever'],
    'wrong bearer' => ['correct-token', 'wrong-token'],
    'missing bearer' => ['correct-token', null],
]);

it('passes through on a matching bearer', function () {
    config()->set('gezel.middleware.service_token', 'correct-token');

    $request = Request::create('/x', 'POST');
    $request->headers->set('Authorization', 'Bearer correct-token');

    $response = (new VerifyGezelServiceToken)->handle($request, fn () => response('ok'));

    expect($response->getContent())->toBe('ok');
});
