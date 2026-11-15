<?php

namespace App\Filament\Resources\ParcelResource\Pages;

use App\Filament\Resources\ParcelResource;
use App\Filament\Support\FilamentCityScope;
use App\Models\Parcel;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListParcels extends ListRecords
{
    protected static string $resource = ParcelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nouveau Colis'),
        ];
    }

    public function getTabs(): array
    {
        $inTransitStatuses = [
            Parcel::STATUS_ACCEPTED,
            Parcel::STATUS_IN_TRANSIT,
            'assigned',
            'at_sender',
            'picked_up',
            'at_recipient',
        ];

        return [
            'all' => Tab::make('Tous les Colis')
                ->badge(fn (): int => FilamentCityScope::scopedTabCount(ParcelResource::class)),
            'in_transit' => Tab::make('En Transit')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', $inTransitStatuses))
                ->badge(fn (): int => FilamentCityScope::scopedTabCount(ParcelResource::class, fn (Builder $q) => $q->whereIn('status', $inTransitStatuses))),
            'pending' => Tab::make('En Attente')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Parcel::STATUS_PENDING))
                ->badge(fn (): int => FilamentCityScope::scopedTabCount(ParcelResource::class, fn (Builder $q) => $q->where('status', Parcel::STATUS_PENDING))),
            'delivered' => Tab::make('Livrés')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Parcel::STATUS_DELIVERED))
                ->badge(fn (): int => FilamentCityScope::scopedTabCount(ParcelResource::class, fn (Builder $q) => $q->where('status', Parcel::STATUS_DELIVERED))),
            'insured' => Tab::make('Colis Assurés')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('has_insurance', true))
                ->badge(fn (): int => FilamentCityScope::scopedTabCount(ParcelResource::class, fn (Builder $q) => $q->where('has_insurance', true))),
            'locked' => Tab::make('Codes Bloqués')
                ->modifyQueryUsing(fn (Builder $query) => $query->where(fn (Builder $q) => $q->where('sender_code_locked', true)->orWhere('recipient_code_locked', true)))
                ->badge(fn (): int => FilamentCityScope::scopedTabCount(ParcelResource::class, fn (Builder $q) => $q->where(fn (Builder $inner) => $inner->where('sender_code_locked', true)->orWhere('recipient_code_locked', true)))),
            'cancelled' => Tab::make('Annulés')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [Parcel::STATUS_CANCELLED, 'refused']))
                ->badge(fn (): int => FilamentCityScope::scopedTabCount(ParcelResource::class, fn (Builder $q) => $q->whereIn('status', [Parcel::STATUS_CANCELLED, 'refused']))),
        ];
    }
}
