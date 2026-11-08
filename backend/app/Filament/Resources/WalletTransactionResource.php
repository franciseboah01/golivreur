<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WalletTransactionResource\Pages;
use App\Models\WalletTransaction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

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

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Détails de l\'Opération Financière')
                    ->schema([
                        Forms\Components\Select::make('type')
                            ->label('Nature de la transaction')
                            ->options([
                                'adjustment' => 'Ajustement manuel admin',
                                'commission' => 'Prélèvement commission plateforme',
                                'delivery_fee' => 'Rémunération course livreur',
                                'deposit' => 'Rechargement / Dépôt Mobile Money',
                                'order_payment' => 'Paiement commande GoShop',
                                'parcel_payment' => 'Paiement course GoColis',
                                'refund' => 'Remboursement client / indemnisation',
                                'withdrawal' => 'Demande de Retrait vers Mobile Money',
                            ])
                            ->searchable()
                            ->native(false)
                            ->required(),
                        Forms\Components\Select::make('status')
                            ->label('Statut du transfert')
                            ->options([
                                'completed' => 'Validé avec succès',
                                'failed' => 'Échoué / Rejeté',
                                'pending' => 'En cours de traitement',
                            ])
                            ->searchable()
                            ->native(false)
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
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ') . ' F CFA')
                    ->color(fn ($state) => (float) $state >= 0 ? 'success' : 'danger')
                    ->sortable(),

                Tables\Columns\TextColumn::make('balance_after')
                    ->label('Nouveau Solde')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ') . ' F CFA')
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
            ->actions([
                Tables\Actions\EditAction::make()->label('Modifier'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('Supprimer la sélection'),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWalletTransactions::route('/'),
            'create' => Pages\CreateWalletTransaction::route('/create'),
            'edit' => Pages\EditWalletTransaction::route('/{record}/edit'),
        ];
    }
}
