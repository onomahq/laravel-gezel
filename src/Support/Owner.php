<?php

namespace Onomahq\Gezel\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
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

        return $model;
    }

    public static function findByGezelId(string $gezelId): ?Model
    {
        return static::model()::query()->where('gezel_id', $gezelId)->first();
    }

    public static function guardSharedMemoryAcknowledgement(): void
    {
        $model = static::model();

        if (is_subclass_of($model, Authenticatable::class)) {
            return;
        }

        if (config('gezel.owner.acknowledges_shared_memory') === true) {
            return;
        }

        throw new RuntimeException(
            "gezel.owner.model [{$model}] is not an Illuminate\\Foundation\\Auth\\User subclass. A non-User owner means one Gezel container and one shared agent memory across every member of that model — a deliberate product decision, not a default. Set gezel.owner.acknowledges_shared_memory = true in config/gezel.php to confirm you intend this."
        );
    }
}
