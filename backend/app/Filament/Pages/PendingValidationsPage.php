<?php

namespace App\Filament\Pages;

use App\Models\{Courier, Merchant};
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Page regroupant les commerces et livreurs en attente de validation / activation.
 * Permet aux City Agents, Support et Managers de valider les dossiers d'un clic.
 */
class PendingValidationsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Partenaires';

    protected static ?string $navigationLabel = 'Validations en attente';

    protected static ?string $title = 'Dossiers Partenaires en Attente d\'Activation';

    protected static string $view = 'filament.pages.pending-validations';

    protected static ?int $navigationSort = 0;

    public static function canAccess(): bool
    {
        $user = auth()->user();
        return $user && in_array($user->role, [
            'city_agent',
            'city_support',
            'city_manager',
            'national_support',
            'dg_national',
            'super_admin',
        ], true);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Merchant::where('is_active', false)->count() + Courier::where('is_active', false)->count();
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Merchant::query()->where('is_active', false))
            ->heading('Commerces Partenaires à Valider')
            ->description('Vérifiez les pièces justificatives et activez les nouveaux commerces.')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nom du Commerce')
                    ->weight('bold')
                    ->searchable(),
                Tables\Columns\TextColumn::make('city.name')
                    ->label('Ville')
                    ->badge(),
                Tables\Columns\TextColumn::make('category')
                    ->label('Catégorie')
                    ->badge()
                    ->color('info'),
                Tables\Columns\TextColumn::make('address')
                    ->label('Adresse')
                    ->limit(30),
                Tables\Columns\TextColumn::make('commission_rate')
                    ->label('Taux Commission')
                    ->suffix('%'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date inscription')
                    ->dateTime('d/m/Y H:i'),
            ])
            ->actions([
                Tables\Actions\Action::make('activateMerchant')
                    ->label('Activer le commerce')
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->button()
                    ->requiresConfirmation()
                    ->modalHeading('Confirmer l\'activation de ce commerce')
                    ->modalDescription('Le commerce apparaîtra immédiatement dans l\'application GoLivreur Client pour les commandes.')
                    ->action(function (Merchant $record): void {
                        $record->update([
                            'is_active'     => true,
                            'activated_at'  => now(),
                            'activated_by'  => auth()->id(),
                        ]);

                        Notification::make()
                            ->title("Commerce {$record->name} activé avec succès")
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('Aucun commerce en attente de validation')
            ->emptyStateDescription('Tous les commerces inscrits ont été traités.');
    }
}

