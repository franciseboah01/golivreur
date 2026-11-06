<?php

namespace App\Filament\Resources\ParcelResource\Pages;

use App\Filament\Resources\ParcelResource;
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
        return [
            'all' => Tab::make('Tous les Colis')
                ->badge(Parcel::count()),
            'in_transit' => Tab::make('En Transit')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [
                    Parcel::STATUS_ACCEPTED,
                    Parcel::STATUS_IN_TRANSIT,
                    'assigned',
                    'at_sender',
                    'picked_up',
                    'at_recipient',
                ]))
                ->badge(Parcel::whereIn('status', [
                    Parcel::STATUS_ACCEPTED,
                    Parcel::STATUS_IN_TRANSIT,
                    'assigned',
                    'at_sender',
                    'picked_up',
                    'at_recipient',
                ])->count()),
            'pending' => Tab::make('En Attente')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Parcel::STATUS_PENDING))
                ->badge(Parcel::where('status', Parcel::STATUS_PENDING)->count()),
            'delivered' => Tab::make('Livrés')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Parcel::STATUS_DELIVERED))
                ->badge(Parcel::where('status', Parcel::STATUS_DELIVERED)->count()),
            'insured' => Tab::make('Colis Assurés')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('has_insurance', true))
                ->badge(Parcel::where('has_insurance', true)->count()),
            'locked' => Tab::make('Codes Bloqués')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('sender_code_locked', true)->orWhere('recipient_code_locked', true))
                ->badge(Parcel::where('sender_code_locked', true)->orWhere('recipient_code_locked', true)->count()),
            'cancelled' => Tab::make('Annulés')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [Parcel::STATUS_CANCELLED, 'refused']))
                ->badge(Parcel::whereIn('status', [Parcel::STATUS_CANCELLED, 'refused'])->count()),
        ];
    }
}
