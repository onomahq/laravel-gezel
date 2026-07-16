<?php

use Onomahq\Gezel\Streaming\StreamRequest;

beforeEach(function () {
    config()->set('gezel.app_id', 'stagent');
});

it('builds the InboundEnvelope with tenant_id from gezel.app_id', function () {
    $envelope = (new StreamRequest('gezel-1', 'chat-1', 'hello'))->toEnvelope();

    expect($envelope)->toBe([
        'tenant_id' => 'stagent',
        'platform' => 'web',
        'external_chat_id' => 'chat-1',
        'text' => 'hello',
    ]);
});

it('includes persona_id and turn_context in the envelope only when given', function () {
    $envelope = (new StreamRequest(
        gezelId: 'gezel-1',
        externalChatId: 'chat-1',
        text: 'hello',
        personaId: 'coach',
        turnContext: 'the user is in a 1:1 with...',
    ))->toEnvelope();

    expect($envelope)->toBe([
        'tenant_id' => 'stagent',
        'platform' => 'web',
        'external_chat_id' => 'chat-1',
        'text' => 'hello',
        'persona_id' => 'coach',
        'turn_context' => 'the user is in a 1:1 with...',
    ]);
});

it('omits empty-string persona_id and turn_context', function () {
    $envelope = (new StreamRequest('gezel-1', 'chat-1', 'hello', '', ''))->toEnvelope();

    expect($envelope)->not->toHaveKey('persona_id');
    expect($envelope)->not->toHaveKey('turn_context');
});
