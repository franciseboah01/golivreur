<?php

namespace App\Filament\Resources;

use App\Filament\Components\SearchableSelect;
use App\Filament\Resources\WalletTransactionResource\Pages;
use App\Models\WalletTransaction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WalletTransactionResource extends Resource
{
    protected static ?string $model = WalletTransaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Finances';

    protected static ?string $navigationLabel = 'Transactions Portefeuille';

    protected static ?string $modelLabel = 'Transaction Portefeuille';

    protected static ?string $pluralModelLabel = 'Transactions Portefeuille';

    protected static ?int $navigationSort = 7;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->hasHabilitationTab('finances');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user && ($user->is_local_admin || str_starts_with((string) $user->role, 'city_')) && $user->city_id) {
            $cityId = (int) $user->city_id;
            $query->where(function ($q) use ($cityId) {
                $q->whereHas('order', fn ($o) => $o->where('city_id', $cityId))
                    ->orWhereHas('parcel', fn ($p) => $p->where('city_id', $cityId))
                    ->orWhereHas('wallet', function ($w) use ($cityId) {
                        $w->where(function ($sub) use ($cityId) {
                            $sub->where('owner_type', 'merchant')
                                ->whereIn('owner_id', \App\Models\Merchant::where('city_id', $cityId)->select('id'));
                        })->orWhere(function ($sub2) use ($cityId) {
                            $sub2->where('owner_type', 'courier')
                                ->whereIn('owner_id', \App\Models\Courier::where('city_id', $cityId)->select('id'));
                        });
                    });
            });
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Détails de l\'Opération Financière')
                    ->schema([
                        // Listes déroulantes en haut avec recherche live et tri alphabétique
                        SearchableSelect::make('type')
                            ->label('Nature de la transaction')
                            ->options(SearchableSelect::sortedOptions([
                                'adjustment' => 'Ajustement manuel admin',
                                'withdrawal' => 'Demande de Retrait vers Mobile Money',
                                'parcel_payment' => 'Paiement course GoColis',
                                'order_payment' => 'Paiement commande GoShop',
                                'commission' => 'Prélèvement commission plateforme',
                                'deposit' => 'Rechargement / Dépôt Mobile Money',
                                'refund' => 'Remboursement client / indemnisation',
                                'delivery_fee' => 'Rémunération course livreur',
                            ]))
                            ->required(),
                        SearchableSelect::make('status')
                            ->label('Statut du transfert')
                            ->options(SearchableSelect::sortedOptions([
                                'failed' => 'Échoué / Rejeté',
                                'pending' => 'En cours de traitement',
                                'completed' => 'Validé avec succès',
                            ]))
                            ->required(),
                        Forms\Components\TextInput::make('reference')
                            ->label('Référence unique')
                            ->required()
                            ->maxLength(100),
                        Forms\Components\TextInput::make('amount')
                            ->label('Montant (F CFA)')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('balance_before')
                            ->label('Solde initial (F CFA)')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('balance_after')
                            ->label('Nouveau solde (F CFA)')
                            ->numeric()
                            ->required(),
                        Forms\Components\Textarea::make('description')
                            ->label('Motif / Libellé de l\'opération')
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('rowIndex')
                    ->rowIndex()
                    ->label('N°'),

                Tables\Columns\TextColumn::make('reference')
                    ->label('Référence')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('wallet_id')
                    ->label('Portefeuille N°')
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'deposit' => 'Dépôt',
                        'withdrawal' => 'Retrait',
                        'order_payment' => 'Paiement Commande',
                        'parcel_payment' => 'Paiement Course',
                        'delivery_fee' => 'Course Livreur',
                        'commission' => 'Commission',
                        'refund' => 'Remboursement',
                        'adjustment' => 'Ajustement',
                        default => ucfirst($state),
                    }),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Montant')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' F CFA')
                    ->color(fn ($state) => (float) $state >= 0 ? 'success' : 'danger')
                    ->sortable(),

                Tables\Columns\TextColumn::make('balance_after')
                    ->label('Nouveau Solde')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' F CFA')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'pending' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'completed' => 'Validé',
                        'pending' => 'En attente',
                        'failed' => 'Échoué',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Filtrer par Type')
                    ->options([
                        'deposit' => 'Dépôt',
                        'withdrawal' => 'Retrait',
                        'order_payment' => 'Paiement Commande',
                        'parcel_payment' => 'Paiement Course',
                        'delivery_fee' => 'Course Livreur',
                        'commission' => 'Commission',
                        'refund' => 'Remboursement',
                    ]),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Filtrer par Statut')
                    ->options([
                        'completed' => 'Validé',
                        'pending' => 'En attente',
                        'failed' => 'Échoué',
                    ]),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWalletTransactions::route('/'),
        ];
    }
}
