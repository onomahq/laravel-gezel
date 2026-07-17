<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Onomahq\Gezel\Auth\Drivers\Sanctum\SanctumIssuer;
use Onomahq\Gezel\Contracts\AgentMessageHandler;
use Onomahq\Gezel\Contracts\OwnerMembershipVerifier;
use Onomahq\Gezel\Contracts\TargetOwnershipVerifier;
use Onomahq\Gezel\Events\GezelAgentMessageReceived;
use Onomahq\Gezel\Http\RateLimitKeyResolver;
use Onomahq\Gezel\Tests\Fixtures\GezelUser;

beforeEach(function () {
    migrateGezelOwnerTable(GezelUser::class);
    migratePersonalAccessTokensTable();
});

afterEach(function () {
    Schema::dropIfExists('users');
    Schema::dropIfExists('personal_access_tokens');
});

function agentMessagesUri(): string
{
    return '/'.trim(config('gezel.routes.prefix'), '/').'/agent-messages';
}

function containerBearer(): array
{
    $owner = GezelUser::create(['name' => 'Ada']);
    $owner->ensureGezelId();

    return [$owner, (new SanctumIssuer)->issue($owner)];
}

function allowAllTargets(): void
{
    app()->bind(TargetOwnershipVerifier::class, fn () => new class implements TargetOwnershipVerifier
    {
        public function verify(Model $owner, array $payload): bool
        {
            return true;
        }
    });
}

it('404s without a bearer', function () {
    $this->postJson(agentMessagesUri(), ['message' => 'hi'])
        ->assertNotFound()
        ->assertExactJson(['error' => 'not found']);
});

it('404s with a bearer that is not a container token', function () {
    $owner = GezelUser::create(['name' => 'Ada']);
    $owner->ensureGezelId();

    $bearer = $owner->createToken('some-other-token', ['*'])->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson(agentMessagesUri(), ['message' => 'hi'])
        ->assertNotFound()
        ->assertExactJson(['error' => 'not found']);
});

it('404s a validation failure instead of returning 422', function () {
    [, $bearer] = containerBearer();

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson(agentMessagesUri(), [])
        ->assertNotFound()
        ->assertExactJson(['error' => 'not found']);
});

it('refuses identically however the request fails, so a prober learns nothing', function () {
    [, $bearer] = containerBearer();

    $noBearer = $this->postJson(agentMessagesUri(), ['message' => 'hi']);
    $badBearer = $this->withHeader('Authorization', 'Bearer nope')->postJson(agentMessagesUri(), ['message' => 'hi']);
    $badPayload = $this->withHeader('Authorization', "Bearer {$bearer}")->postJson(agentMessagesUri(), []);

    expect($noBearer->status())->toBe(404);
    expect($badBearer->json())->toBe($noBearer->json());
    expect($badPayload->json())->toBe($noBearer->json());
});

it('invokes the default handler, firing GezelAgentMessageReceived with the resolved owner', function () {
    Event::fake();

    [$owner, $bearer] = containerBearer();

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson(agentMessagesUri(), ['message' => 'hello'])
        ->assertOk()
        ->assertJson(['status' => 'sent']);

    Event::assertDispatched(GezelAgentMessageReceived::class, function ($event) use ($owner) {
        return $event->owner->is($owner) && $event->payload['message'] === 'hello';
    });
});

it('404s when the bound OwnerMembershipVerifier rejects the owner', function () {
    $this->app->bind(OwnerMembershipVerifier::class, fn () => new class implements OwnerMembershipVerifier
    {
        public function verify(Model $owner): bool
        {
            return false;
        }
    });

    [, $bearer] = containerBearer();

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson(agentMessagesUri(), ['message' => 'hello'])
        ->assertNotFound()
        ->assertExactJson(['error' => 'not found']);
});

it('refuses a targeted message by default, because the package cannot prove the target is the owners', function () {
    Event::fake();
    Log::spy();

    [, $bearer] = containerBearer();

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson(agentMessagesUri(), ['message' => 'hello', 'chat_id' => 'someone-elses-chat'])
        ->assertNotFound()
        ->assertExactJson(['error' => 'not found']);

    Event::assertNotDispatched(GezelAgentMessageReceived::class);
    Log::shouldHaveReceived('warning')->once();
});

it('invokes the target verifier before the handler, and never the handler when it rejects', function () {
    $calls = [];

    $this->app->bind(TargetOwnershipVerifier::class, function () use (&$calls) {
        return new class($calls) implements TargetOwnershipVerifier
        {
            public function __construct(private array &$calls) {}

            public function verify(Model $owner, array $payload): bool
            {
                $this->calls[] = 'verify';

                return false;
            }
        };
    });

    $this->app->bind(AgentMessageHandler::class, function () use (&$calls) {
        return new class($calls) implements AgentMessageHandler
        {
            public function __construct(private array &$calls) {}

            public function handle(Model $owner, array $payload): void
            {
                $this->calls[] = 'handle';
            }
        };
    });

    [, $bearer] = containerBearer();

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson(agentMessagesUri(), ['message' => 'hello', 'chat_id' => 'c-1'])
        ->assertNotFound();

    expect($calls)->toBe(['verify']);
});

it('delivers a targeted message once a bound verifier vouches for the target', function () {
    allowAllTargets();
    Event::fake();

    [$owner, $bearer] = containerBearer();

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson(agentMessagesUri(), ['message' => 'hello', 'chat_id' => 'c-1'])
        ->assertOk();

    Event::assertDispatched(GezelAgentMessageReceived::class, function ($event) use ($owner) {
        return $event->owner->is($owner) && $event->payload['chat_id'] === 'c-1';
    });
});

it('strips payload keys the package never validated before the handler sees them', function () {
    allowAllTargets();

    $received = null;

    $this->app->bind(AgentMessageHandler::class, function () use (&$received) {
        return new class($received) implements AgentMessageHandler
        {
            public function __construct(private mixed &$received) {}

            public function handle(Model $owner, array $payload): void
            {
                $this->received = $payload;
            }
        };
    });

    [, $bearer] = containerBearer();

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson(agentMessagesUri(), [
            'message' => 'hello',
            'chat_id' => 'c-1',
            'user_id' => 'attacker-controlled',
            'is_admin' => true,
        ])
        ->assertOk();

    expect($received)->toBe(['message' => 'hello', 'chat_id' => 'c-1']);
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

    [$owner, $bearer] = containerBearer();

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson(agentMessagesUri(), ['message' => 'hello'])
        ->assertOk();

    expect($received[0]->is($owner))->toBeTrue();
    expect($received[1]['message'])->toBe('hello');
});

it('resolves the principal before the limiter runs, so the limiter keys on it and not a body field', function () {
    $seen = null;

    RateLimiter::for('gezel-internal', function (Request $request) use (&$seen) {
        $seen = app(RateLimitKeyResolver::class)->resolve($request);

        return Limit::none();
    });

    [$owner, $bearer] = containerBearer();

    $this->withHeader('Authorization', "Bearer {$bearer}")
        ->postJson(agentMessagesUri(), ['message' => 'hello', 'user_id' => 'attacker-controlled'])
        ->assertOk();

    expect($seen)->toBe($owner->gezel_id);
});
