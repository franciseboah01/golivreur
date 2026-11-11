<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\OrderResource;
use App\Models\Order;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class LatestOrdersWidget extends BaseWidget
{
    protected static ?string $heading = 'Dernières Commandes GoShop en Temps Réel';

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(Order::query()->latest()->limit(5))
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('N° Commande')
                    ->sortable(),

                Tables\Columns\TextColumn::make('client.name')
                    ->label('Client'),

                Tables\Columns\TextColumn::make('merchant.name')
                    ->label('Commerce Partenaire'),

                Tables\Columns\TextColumn::make('courier.user.name')
                    ->label('Livreur')
                    ->placeholder('En recherche d\'un livreur'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'gray',
                        'accepted' => 'info',
                        'preparing' => 'warning',
                        'ready' => 'primary',
                        'delivering' => 'info',
                        'delivered' => 'success',
                        'cancelled' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'En attente',
                        'accepted' => 'Acceptée',
                        'preparing' => 'En préparation',
                        'ready' => 'Prête',
                        'delivering' => 'En cours de livraison',
                        'delivered' => 'Livrée',
                        'cancelled' => 'Annulée',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('products_total')
                    ->label('Total Articles')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' F CFA'),

                Tables\Columns\TextColumn::make('delivery_fee')
                    ->label('Frais Livraison')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' F CFA'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Heure')
                    ->since(),
            ])
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label('Détails')
                    ->icon('heroicon-m-eye')
                    ->url(fn (Order $record): string => OrderResource::getUrl('edit', ['record' => $record])),
            ])
            ->paginated(false);
    }
}
