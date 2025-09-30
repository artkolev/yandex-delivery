<?php

declare(strict_types=1);

namespace artkolev\yandex_delivery\DTO;

use JsonSerializable;

abstract class DTO implements JsonSerializable
{
    abstract public function toArray(): array;

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public static function map(array $models): array
    {
        return array_map([static::class, 'toModel'], $models);
    }

    private static function toModel(mixed $model): static
    {
        return \method_exists(static::class, 'fromModel')
            ? static::fromModel($model)
            : new static($model);
    }
}
