<?php

namespace App\Filament\Pages;

use App\Filament\Support\FilamentCityScope;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Parcel;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Page dédiée au traitement des Litiges et au Déblocage des codes de confirmation.
 * Accessible au Support et Managers pour intervenir après vérification vocale.
 */
class DisputesPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationGroup = 'Opérations GoShop';

    protected static ?string $navigationLabel = 'Litiges & Codes Bloqués';

    protected static ?string $title = 'Gestion des Litiges & Déblocage des Codes';

    protected static string $view = 'filament.pages.disputes-page';

    protected static ?int $navigationSort = 10;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->hasHabilitationTab('disputes');
    }

    public static function getNavigationBadge(): ?string
    {
        $lockedOrders = FilamentCityScope::scopeByCity(
            Order::where('confirmation_locked', true)
        )->count();
        $lockedParcels = FilamentCityScope::scopeByCity(
            Parcel::where(fn ($q) => $q->where('sender_code_locked', true)->orWhere('recipient_code_locked', true))
        )->count();
        $total = $lockedOrders + $lockedParcels;

        return $total > 0 ? (string) $total : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(FilamentCityScope::scopeByCity(Order::query()->where('confirmation_locked', true)))
            ->heading('Commandes GoShop Bloquées (Échec de Code OTP Client)')
            ->description('Appelez le client pour confirmer son identité avant de débloquer la livraison.')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('N° Commande')
                    ->formatStateUsing(fn ($state): string => "#{$state}")
                    ->weight('bold')
                    ->searchable(),

                Tables\Columns\TextColumn::make('client.name')
                    ->label('Client')
                    ->description(fn (Order $record): string => $record->client->phone ?? '-'),

                Tables\Columns\TextColumn::make('courier.user.name')
                    ->label('Livreur assigné')
                    ->placeholder('Non assigné'),

                Tables\Columns\TextColumn::make('merchant.name')
                    ->label('Commerce')
                    ->limit(25),

                Tables\Columns\TextColumn::make('admin_confirmation_code')
                    ->label('OTP Livraison')
                    ->badge()
                    ->color('primary')
                    ->fontFamily('mono')
                    ->copyable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('confirmation_attempts')
                    ->label('Tentatives erronées')
                    ->badge()
                    ->color('danger'),

                Tables\Columns\TextColumn::make('total_client')
                    ->label('Montant total')
                    ->money('XOF'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i'),
            ])
            ->actions([
                Tables\Actions\Action::make('unlockCode')
                    ->label('Débloquer (Appel Vocal Validé)')
                    ->icon('heroicon-m-phone-arrow-up-right')
                    ->color('success')
                    ->button()
                    ->requiresConfirmation()
                    ->modalHeading(fn (Order $record): string => "Débloquer la commande #{$record->id}")
                    ->modalDescription('Confirmez-vous avoir appelé le client et validé verbalement la réception de la livraison ?')
                    ->action(function (Order $record): void {
                        FilamentCityScope::authorizeRecord($record);

                        $record->update([
                            'confirmation_locked' => false,
                            'confirmation_attempts' => 0,
                            'confirmation_unlocked_by' => auth()->id(),
                        ]);

                        ActivityLog::create([
                            'user_id' => auth()->id(),
                            'action' => 'order_code_unlocked',
                            'entity_type' => 'order',
                            'entity_id' => $record->id,
                            'properties' => [
                                'order_id' => $record->id,
                                'client_id' => $record->client_id,
                                'courier_id' => $record->courier_id,
                            ],
                            'ip_address' => request()->ip(),
                            'severity' => 'warning',
                        ]);

                        Notification::make()
                            ->title("Commande #{$record->id} débloquée")
                            ->body('Le livreur peut de nouveau valider la course.')
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('Aucune commande bloquée')
            ->emptyStateDescription('Toutes les livraisons se déroulent normalement.');
    }
}
