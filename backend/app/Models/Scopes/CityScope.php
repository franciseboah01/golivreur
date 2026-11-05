<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class CityScope implements Scope
{
    protected static ?int $currentCityId = null;

    public static function setCurrentCityId(?int $cityId): void
    {
        self::$currentCityId = $cityId;
    }

    public static function getCurrentCityId(): ?int
    {
        return self::$currentCityId;
    }

    public function apply(Builder $builder, Model $model): void
    {
        if (self::$currentCityId !== null) {
            $builder->where($model->qualifyColumn('city_id'), self::$currentCityId);
        }
    }
}
