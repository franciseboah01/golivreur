<?php

namespace App\Filament\Pages\Widgets;

use App\Filament\Resources\MerchantResource;
use App\Filament\Support\FilamentCityScope;
use App\Models\ActivityLog;
use App\Models\Merchant;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class PendingMerchantsWidget extends BaseWidget
{
    protected static ?string $heading = 'Commerces Partenaires en Attente de Validation';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $query = FilamentCityScope::scopeByCity(
            Merchant::query()->where('is_active', false)->latest('created_at')
        );

        return $table
            ->query($query)
            ->heading('🏪 Commerces Partenaires en Attente de Validation')
            ->description('Vérifiez les pièces justificatives, les coordonnées et activez les nouveaux commerces.')
            ->columns([
                Tables\Columns\TextColumn::make('rowIndex')
                    ->rowIndex()
                    ->label('N°'),

                Tables\Columns\ImageColumn::make('photo_path')
                    ->label('Logo / Photo')
                    ->disk('public')
                    ->circular()
                    ->defaultImageUrl(asset('images/placeholder-merchant.png')),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nom du Commerce')
                    ->weight('bold')
                    ->searchable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Gérant / Contact')
                    ->placeholder('Non assigné'),

                Tables\Columns\TextColumn::make('user.phone')
                    ->label('Téléphone')
                    ->copyable()
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('city.name')
                    ->label('Ville')
                    ->badge(),

                Tables\Columns\TextColumn::make('category')
                    ->label('Catégorie')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('commission_rate')
                    ->label('Commission')
                    ->suffix('%'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date Inscription')
                    ->dateTime('d/m/Y H:i'),
            ])
            ->actions([
                Tables\Actions\Action::make('activateMerchant')
                    ->label('Valider & Activer')
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->button()
                    ->requiresConfirmation()
                    ->modalHeading(fn (Merchant $record) => "Confirmer l'activation de {$record->name}")
                    ->modalDescription('Le commerce apparaîtra immédiatement dans l\'application GoLivreur Client pour recevoir des commandes.')
                    ->action(function (Merchant $record): void {
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
                            'action' => 'merchant_validated_from_pending',
                            'entity_type' => 'merchant',
                            'entity_id' => $record->id,
                            'properties' => [
                                'merchant_id' => $record->id,
                                'merchant_name' => $record->name,
                            ],
                            'ip_address' => request()->ip(),
                            'severity' => 'info',
                        ]);

                        Notification::make()
                            ->title("Commerce {$record->name} activé avec succès !")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('edit')
                    ->label('Détail / Modifier')
                    ->icon('heroicon-m-pencil-square')
                    ->color('gray')
                    ->url(fn (Merchant $record) => MerchantResource::getUrl('edit', ['record' => $record])),
            ])
            ->emptyStateIcon('heroicon-o-check-badge')
            ->emptyStateHeading('Aucun commerce en attente de validation')
            ->emptyStateDescription('Tous les commerces partenaires enregistrés ont été validés et activés.');
    }
}
