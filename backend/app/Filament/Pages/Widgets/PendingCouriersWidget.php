<?php

namespace App\Filament\Pages\Widgets;

use App\Filament\Resources\CourierResource;
use App\Filament\Support\FilamentCityScope;
use App\Models\ActivityLog;
use App\Models\Courier;
use App\Models\VehicleType;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class PendingCouriersWidget extends BaseWidget
{
    protected static ?string $heading = 'Livreurs Partenaires en Attente de Validation';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $query = FilamentCityScope::scopeByCity(
            Courier::query()->where('is_active', false)->latest('created_at')
        );

        return $table
            ->query($query)
            ->heading('🚴 Livreurs Partenaires en Attente de Validation')
            ->description('Vérifiez l\'identité, le véhicule et activez les comptes des nouveaux livreurs.')
            ->columns([
                Tables\Columns\TextColumn::make('rowIndex')
                    ->rowIndex()
                    ->label('N°'),

                Tables\Columns\ImageColumn::make('photo_path')
                    ->label('Photo')
                    ->disk('public')
                    ->circular()
                    ->defaultImageUrl(asset('images/placeholder-driver.png')),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Nom du Livreur')
                    ->weight('bold')
                    ->searchable(),

                Tables\Columns\TextColumn::make('user.phone')
                    ->label('Téléphone')
                    ->copyable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('city.name')
                    ->label('Ville')
                    ->badge(),

                Tables\Columns\TextColumn::make('vehicle_type')
                    ->label('Véhicule')
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn (?string $state): string => $state ? (VehicleType::where('slug', $state)->value('name') ?? ucfirst($state)) : '-'),

                Tables\Columns\TextColumn::make('vehicle_plate')
                    ->label('Plaque')
                    ->placeholder('Non renseignée'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date Inscription')
                    ->dateTime('d/m/Y H:i'),
            ])
            ->actions([
                Tables\Actions\Action::make('validateCourier')
                    ->label('Valider & Activer')
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->button()
                    ->requiresConfirmation()
                    ->modalHeading(fn (Courier $record) => "Valider le profil de {$record->user?->name}")
                    ->modalDescription('Le livreur sera immédiatement activé et pourra se connecter à l\'application GoLivreur Driver pour accepter des courses.')
                    ->action(function (Courier $record): void {
                        FilamentCityScope::authorizeRecord($record);

                        $record->update([
                            'is_active' => true,
                            'activated_at' => now(),
                            'activated_by' => auth()->id(),
                        ]);

                        if ($record->user) {
                            $record->user->update(['is_active' => true]);
                        }

                        ActivityLog::create([
                            'user_id' => auth()->id(),
                            'action' => 'courier_validated_from_pending',
                            'entity_type' => 'courier',
                            'entity_id' => $record->id,
                            'properties' => [
                                'courier_id' => $record->id,
                                'user_name' => $record->user?->name,
                            ],
                            'ip_address' => request()->ip(),
                            'severity' => 'info',
                        ]);

                        Notification::make()
                            ->title("Livreur {$record->user?->name} validé et activé !")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('edit')
                    ->label('Détail / Modifier')
                    ->icon('heroicon-m-pencil-square')
                    ->color('gray')
                    ->url(fn (Courier $record) => CourierResource::getUrl('edit', ['record' => $record])),
            ])
            ->emptyStateIcon('heroicon-o-check-badge')
            ->emptyStateHeading('Aucun livreur en attente de validation')
            ->emptyStateDescription('Tous les profils de livreurs enregistrés ont été validés et activés.');
    }
}
