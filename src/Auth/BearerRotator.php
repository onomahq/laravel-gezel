<?php

namespace Onomahq\Gezel\Auth;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Onomahq\Gezel\Contracts\ContainerBearerIssuer;
use RuntimeException;

/**
 * Enforces the safe order for rotating a container bearer: mint the new token
 * (issuing it persists the token row), push it to the middleware, and only
 * delete the old token once that push succeeds. A failed middleware call
 * leaves the previous, still-working bearer in place instead of locking the
 * container out.
 *
 * The whole sequence runs under a per-owner lock so a scheduled reconcile and
 * a manual recreate cannot interleave and race each other's mint/delete steps.
 * The lock is only as good as the store behind it: set `gezel.lock_store` to a
 * driver that shares state across processes (redis, memcached, database) when
 * the default cache store does not, or the lock is decoration.
 *
 * Callers: always a queued job's `handle()`, never its constructor. A
 * plaintext bearer captured in a constructor gets serialized into
 * `failed_jobs`/Horizon; minting must happen once the job actually runs.
 */
final class BearerRotator
{
    public function __construct(private readonly ContainerBearerIssuer $issuer) {}

    /**
     * @param  callable(string $bearer): void  $pushToMiddleware  Push the new bearer to the middleware (e.g. writeConfig). Throwing aborts the rotation before $deleteOldBearers runs.
     * @param  callable(): void  $deleteOldBearers  Revoke the owner's previous bearer(s). Only called after $pushToMiddleware succeeds.
     *
     * @throws RuntimeException When called inside a database transaction, or when gezel.lock_store cannot issue locks.
     * @throws LockTimeoutException When another rotation for this owner holds the lock for longer than 10 seconds.
     */
    public function rotate(Model $owner, callable $pushToMiddleware, callable $deleteOldBearers): string
    {
        // Minting is only durable if it is committed. Inside a transaction the
        // token row is invisible to everyone else, so the middleware would
        // take delivery of a bearer the container cannot yet authenticate
        // with, and a rollback would hand it one that never existed. Asked on
        // the owner's connection: that is where the token rows live.
        if ($owner->getConnection()->transactionLevel() > 0) {
            throw new RuntimeException('BearerRotator cannot run inside a database transaction because the middleware would receive a bearer whose token row is not committed yet.');
        }

        // The lock must outlive $pushToMiddleware's own HTTP timeout, or a
        // slow-but-successful call drops the lock while still in flight and
        // a concurrent reconcile walks in behind it.
        $lockSeconds = ((int) config('gezel.timeout', 120)) + 30;

        return $this->lockStore()
            ->lock($this->lockKey($owner), $lockSeconds)
            ->block(10, function () use ($owner, $pushToMiddleware, $deleteOldBearers): string {
                $bearer = $this->issuer->issue($owner);

                $pushToMiddleware($bearer);
                $deleteOldBearers();

                return $bearer;
            });
    }

    private function lockStore(): LockProvider
    {
        $store = Cache::store(config('gezel.lock_store'))->getStore();

        if (! $store instanceof LockProvider) {
            throw new RuntimeException('The cache store behind gezel.lock_store cannot issue locks, so a reconcile and a recreate could rotate the same owner at once.');
        }

        return $store;
    }

    /**
     * Prefixed with app_id like every other Gezel cache key: owner primary
     * keys collide across apps, so Onoma's owner 1 must not hold the lock for
     * Stagent's owner 1 when they share a cache store.
     */
    private function lockKey(Model $owner): string
    {
        return sprintf('gezel:%s:bearer-rotate:%s', config('gezel.app_id'), $owner->getKey());
    }
}
