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

        // An agent is personal. A model that cannot authenticate stands for a
        // group, and one container per group means one agent memory read by
        // every member of it — so the owner must be an individual, with no way
        // to opt out.
        if (! is_a($model, Authenticatable::class, true)) {
            throw new RuntimeException("gezel.owner.model [{$model}] cannot authenticate, so it stands for a group rather than a person, and every member would share one container and one agent memory. A Gezel agent is always personal: point gezel.owner.model at the model your users log in as.");
        }
    }
}
