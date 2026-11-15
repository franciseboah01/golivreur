<?php

namespace App\Filament\Support;

use App\Models\City;
use App\Models\User;
use Filament\Forms;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class FilamentCityScope
{
    public static function user(?User $user = null): ?User
    {
        $resolved = $user ?? auth()->user();

        return $resolved instanceof User ? $resolved : null;
    }

    /**
     * Détermine si l'administrateur courant est un administrateur local (périmètre ville).
     */
    public static function isLocal(?User $user = null): bool
    {
        $resolved = self::user($user);

        if (! $resolved) {
            return false;
        }

        if ($resolved->is_national_admin) {
            return false;
        }

        return $resolved->is_local_admin || str_starts_with((string) $resolved->role, 'city_');
    }

    /**
     * Retourne l'identifiant de ville de l'administrateur local, ou null.
     */
    public static function localCityId(?User $user = null): ?int
    {
        $resolved = self::user($user);

        if (! $resolved || ! self::isLocal($resolved)) {
            return null;
        }

        return $resolved->city_id !== null ? (int) $resolved->city_id : null;
    }

    /**
     * Vérifie que l'administrateur courant a le droit d'opérer (faux si rôle local sans ville).
     */
    public static function hasValidLocalCity(?User $user = null): bool
    {
        $resolved = self::user($user);

        if (! $resolved) {
            return false;
        }

        if (! self::isLocal($resolved)) {
            return true;
        }

        return self::localCityId($resolved) !== null;
    }

    /**
     * Applique le périmètre ville sur une requête Eloquent (fail-closed si admin local sans city_id).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function scopeByCity(Builder $query, string $column = 'city_id', ?User $user = null): Builder
    {
        $resolved = self::user($user);

        if (! $resolved) {
            return $query->whereRaw('1 = 0');
        }

        if (! self::isLocal($resolved)) {
            return $query;
        }

        $cityId = self::localCityId($resolved);

        if ($cityId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where($column, $cityId);
    }

    public static function canAccessCityId(?int $cityId, ?User $user = null): bool
    {
        $resolved = self::user($user);

        if (! $resolved) {
            return false;
        }

        if (! self::isLocal($resolved)) {
            return true;
        }

        $localCityId = self::localCityId($resolved);

        return $localCityId !== null && $cityId !== null && $localCityId === $cityId;
    }

    public static function authorizeCityId(
        ?int $cityId,
        ?User $user = null,
        string $message = 'Action non autorisée en dehors de votre ville.'
    ): void {
        if (! self::canAccessCityId($cityId, $user)) {
            abort(403, $message);
        }
    }

    public static function authorizeRecord(Model $record, string $cityColumn = 'city_id', ?User $user = null): void
    {
        $rawCityId = $record->getAttribute($cityColumn);
        $cityId = $rawCityId !== null ? (int) $rawCityId : null;

        self::authorizeCityId($cityId, $user);
    }

    /**
     * Force côté serveur le city_id à la création pour les rôles locaux (403 si city_id null).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function enforceCityOnCreate(array $data, ?User $user = null): array
    {
        $resolved = self::user($user);

        if (! $resolved) {
            abort(403, 'Authentification administrateur requise.');
        }

        if (! self::isLocal($resolved)) {
            return $data;
        }

        $cityId = self::localCityId($resolved);

        if ($cityId === null) {
            abort(403, 'Aucune ville rattachée à votre compte administrateur.');
        }

        $data['city_id'] = $cityId;

        return $data;
    }

    /**
     * Vérifie le rattachement du record et verrouille city_id à la modification par un rôle local.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function enforceCityOnSave(
        array $data,
        Model $record,
        string $cityColumn = 'city_id',
        ?User $user = null
    ): array {
        $resolved = self::user($user);

        if (! $resolved) {
            abort(403, 'Authentification administrateur requise.');
        }

        if (! self::isLocal($resolved)) {
            return $data;
        }

        $cityId = self::localCityId($resolved);

        if ($cityId === null || (int) $record->getAttribute($cityColumn) !== $cityId) {
            abort(403, 'Action non autorisée sur un enregistrement hors de votre ville.');
        }

        $data[$cityColumn] = $cityId;

        return $data;
    }

    /**
     * Configure un sélecteur de ville Filament (pré-rempli et verrouillé pour les admins locaux).
     */
    public static function configureCitySelect(Forms\Components\Select $select, string $relationshipName = 'city'): Forms\Components\Select
    {
        return $select
            ->relationship(
                name: $relationshipName,
                titleAttribute: 'name',
                modifyQueryUsing: fn (Builder $query): Builder => self::scopeByCity($query, 'cities.id'),
            )
            ->default(fn (): ?int => self::localCityId())
            ->disabled(fn (): bool => self::isLocal())
            ->dehydrated(true);
    }

    /**
     * Nom de ville de l'admin local courant, ou null pour un rôle national / local sans ville.
     */
    public static function scopedCityName(?User $user = null): ?string
    {
        $cityId = self::localCityId($user);

        if ($cityId === null) {
            return null;
        }

        return City::whereKey($cityId)->value('name');
    }

    /**
     * Calcule le compteur d'un onglet List* à partir du getEloquentQuery() scopé de la ressource.
     *
     * @param  class-string<resource>  $resourceClass
     */
    public static function scopedTabCount(string $resourceClass, ?callable $modifier = null): int
    {
        $query = clone $resourceClass::getEloquentQuery();

        if ($modifier !== null) {
            $modifier($query);
        }

        return $query->count();
    }
}
