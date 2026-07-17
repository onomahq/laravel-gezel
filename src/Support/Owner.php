<?php

namespace Onomahq\Gezel\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Onomahq\Gezel\Contracts\GezelOwner;
use RuntimeException;

class Owner
{
    /**
     * @return class-string<Model>
     */
    public static function model(): string
    {
        /** @var class-string<Model> $model */
        $model = config('gezel.owner.model');

        static::guard($model);

        return $model;
    }

    public static function findByGezelId(string $gezelId): ?Model
    {
        return static::model()::query()->where('gezel_id', $gezelId)->first();
    }

    protected static function guard(string $model): void
    {
        if (! class_exists($model)) {
            throw new RuntimeException("gezel.owner.model [{$model}] does not exist.");
        }

        if (! is_a($model, Model::class, true) || ! is_a($model, GezelOwner::class, true)) {
            throw new RuntimeException("gezel.owner.model [{$model}] must be an Eloquent model implementing ".GezelOwner::class.'. Add the HasGezelAgent trait and `implements '.GezelOwner::class.'` to it.');
        }

        if (is_a($model, Authenticatable::class, true)) {
            return;
        }

        if (config('gezel.owner.acknowledges_shared_memory') === true) {
            return;
        }

        throw new RuntimeException("gezel.owner.model [{$model}] cannot authenticate, so every member of it would share one container and one agent memory; set gezel.owner.acknowledges_shared_memory = true in config/gezel.php to confirm you intend this.");
    }
}
