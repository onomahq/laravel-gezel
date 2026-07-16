<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Onomahq\Gezel\Auth\Drivers\Sanctum\SanctumIssuer;
use Onomahq\Gezel\Contracts\AgentMessageHandler;
use Onomahq\Gezel\Contracts\OwnerMembershipVerifier;
use Onomahq\Gezel\Events\GezelAgentMessageReceived;
use Onomahq\Gezel\Tests\Fixtures\GezelUser;

beforeEach(function () {
    migrateGezelOwnerTable(GezelUser::class, 'gezel_users');
    migratePersonalAccessTokensTable();
});

afterEach(function () {
    Schema::dropIfExists('gezel_users');
    Schema::dropIfExists('personal_access_tokens');
});

function agentMessagesUri(): string
{
    return '/'.trim(config('gezel.routes.prefix'), '/').'/agent-messages';
}

it('404s without a bearer', function () {
    $this->postJson(agentMessagesUri(), ['message' => 'hi'])->assertNotFound();
});

it('404s with a bearer that is not a container token', function () {
    $owner = GezelUser::create(['name' => 'Ada']);
    $owner->ensureGezelId();

    $bearer = $owner->createToken('some-other-token', ['*'])->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson(agentMessagesUri(), ['message' => 'hi'])
        ->assertNotFound();
});

it('404s a validation failure instead of returning 422', function () {
    $owner = GezelUser::create(['name' => 'Ada']);
    $owner->ensureGezelId();
    $bearer = (new SanctumIssuer)->issue($owner);

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson(agentMessagesUri(), [])
        ->assertNotFound();
});

it('invokes the default handler, firing GezelAgentMessageReceived with the resolved owner', function () {
    Event::fake();

    $owner = GezelUser::create(['name' => 'Ada']);
    $owner->ensureGezelId();
    $bearer = (new SanctumIssuer)->issue($owner);

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson(agentMessagesUri(), ['message' => 'hello'])
        ->assertOk()
        ->assertJson(['status' => 'sent']);

    Event::assertDispatched(GezelAgentMessageReceived::class, function ($event) use ($owner) {
        return $event->owner->is($owner) && $event->payload['message'] === 'hello';
    });
});

it('404s when the bound OwnerMembershipVerifier rejects the owner', function () {
    $this->app->bind(OwnerMembershipVerifier::class, function () {
        return new class implements OwnerMembershipVerifier
        {
            public function verify(Model $owner): bool
            {
                return false;
            }
        };
    });

    $owner = GezelUser::create(['name' => 'Ada']);
    $owner->ensureGezelId();
    $bearer = (new SanctumIssuer)->issue($owner);

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson(agentMessagesUri(), ['message' => 'hello'])
        ->assertNotFound();
});

it('invokes a custom bound AgentMessageHandler instead of the default', function () {
    $received = null;

    $this->app->bind(AgentMessageHandler::class, function () use (&$received) {
        return new class($received) implements AgentMessageHandler
        {
            public function __construct(private mixed &$received) {}

            public function handle(Model $owner, array $payload): void
            {
                $this->received = [$owner, $payload];
            }
        };
    });

    $owner = GezelUser::create(['name' => 'Ada']);
    $owner->ensureGezelId();
    $bearer = (new SanctumIssuer)->issue($owner);

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson(agentMessagesUri(), ['message' => 'hello'])
        ->assertOk();

    expect($received[0]->is($owner))->toBeTrue();
    expect($received[1]['message'])->toBe('hello');
});
