<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Parcel;

/**
 * Calcule la charge active des livreurs (GoShop + GoColis) et vérifie
 * le plafond configuré `courses.max_actives` par ville.
 */
class CourierCapacityService
{
    public function __construct(private readonly SettingsService $settingsService) {}

    public function obtenirMaxCoursesActives(int $cityId): int
    {
        $raw = $this->settingsService->get('courses.max_actives', $cityId);
        $valeur = is_array($raw)
            ? (int) ($raw['max'] ?? $raw['value'] ?? reset($raw) ?: 1)
            : (int) ($raw ?? 1);

        return max(1, $valeur);
    }

    public function compterCoursesActives(int $courierId): int
    {
        $commandesActives = Order::where('courier_id', $courierId)
            ->whereIn('status', [
                Order::STATUS_ACCEPTED,
                Order::STATUS_READY,
                Order::STATUS_PICKED_UP,
                Order::STATUS_IN_TRANSIT,
            ])
            ->count();

        $colisActifs = Parcel::where('courier_id', $courierId)
            ->whereIn('status', [
                Parcel::STATUS_ACCEPTED,
                Parcel::STATUS_AT_SENDER,
                Parcel::STATUS_PICKED_UP,
                Parcel::STATUS_IN_TRANSIT,
                Parcel::STATUS_AT_RECIPIENT,
            ])
            ->count();

        return $commandesActives + $colisActifs;
    }

    public function peutAccepterNouvelleCourse(int $courierId, int $cityId): bool
    {
        return $this->compterCoursesActives($courierId) < $this->obtenirMaxCoursesActives($cityId);
    }
}

