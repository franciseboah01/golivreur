<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\OrderResource;
use App\Filament\Support\FilamentCityScope;
use App\Models\Order;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class BlockedCodesAlertWidget extends BaseWidget
{
    protected static ?string $heading = 'Alertes Sécurité : Codes de Confirmation Bloqués';

    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        if (! FilamentCityScope::hasValidLocalCity()) {
            return false;
        }

        return FilamentCityScope::scopeByCity(
            Order::where('confirmation_locked', true)
        )->exists();
    }

    public function table(Table $table): Table
    {
        $query = FilamentCityScope::scopeByCity(
            Order::query()->where('confirmation_locked', true)
        );

        return $table
            ->query($query)
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('N° Commande'),

                Tables\Columns\TextColumn::make('client.name')
                    ->label('Client'),

                Tables\Columns\TextColumn::make('client.phone')
                    ->label('Tél. Client'),

                Tables\Columns\TextColumn::make('courier.user.name')
                    ->label('Livreur')
                    ->placeholder('Non assigné'),

                Tables\Columns\TextColumn::make('courier.user.phone')
                    ->label('Tél. Livreur')
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('confirmation_attempts')
                    ->label('Tentatives erronées')
                    ->badge()
                    ->color('danger'),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Bloqué depuis')
                    ->since(),
            ])
            ->actions([
                Tables\Actions\Action::make('unlock')
                    ->label('Débloquer le Code')
                    ->icon('heroicon-m-lock-open')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Déblocage du code de confirmation')
                    ->modalDescription('Confirmez-vous le déblocage de cette commande pour permettre au livreur de finaliser la livraison ?')
                    ->action(function (Order $record): void {
                        FilamentCityScope::authorizeRecord($record);

                        $record->update([
                            'confirmation_locked' => false,
                            'confirmation_attempts' => 0,
                            'confirmation_unlocked_by' => auth()->id(),
                        ]);

                        Notification::make()
                            ->title('Code débloqué avec succès')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('view')
                    ->label('Ouvrir')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->url(fn (Order $record): string => OrderResource::getUrl('edit', ['record' => $record])),
            ])
            ->paginated(false);
    }
}
