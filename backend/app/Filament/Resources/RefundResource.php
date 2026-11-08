<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RefundResource\Pages;
use App\Models\WalletTransaction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ressource Filament dédiée aux Remboursements & Indemnisations clients/partenaires.
 * Conforme au CDC avec respect des plafonds par rôle (2 000 / 10 000 / 50 000 F CFA).
 */
class RefundResource extends Resource
{
    protected static ?string $model = WalletTransaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-refund';

    protected static ?string $navigationGroup = 'Finances';

    protected static ?string $navigationLabel = 'Remboursements';

    protected static ?string $modelLabel = 'Remboursement';

    protected static ?string $pluralModelLabel = 'Remboursements & Indemnisations';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        $user = auth()->user();
        return $user !== null && $user->hasHabilitationTab('refunds');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', 'refund')
            ->latest();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('reference')
                    ->label('Référence opération')
                    ->disabled(),
                Forms\Components\TextInput::make('amount')
                    ->label('Montant remboursé (F CFA)')
                    ->disabled(),
                Forms\Components\Textarea::make('description')
                    ->label('Motif du remboursement')
                    ->disabled()
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('reference')
                    ->label('Référence')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date & Heure')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Montant Remboursé')
                    ->money('XOF')
                    ->color('danger')
                    ->weight('bold')
                    ->sortable(),

                Tables\Columns\TextColumn::make('description')
                    ->label('Motif / Contexte')
                    ->limit(45)
                    ->searchable(),

                Tables\Columns\TextColumn::make('wallet.owner_type')
                    ->label('Bénéficiaire')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'courier' => 'Livreur',
                        'merchant' => 'Commerce',
                        'user', 'client' => 'Client',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('balance_after')
                    ->label('Solde final')
                    ->money('XOF'),
            ])
            ->filters([
                Tables\Filters\Filter::make('recent')
                    ->label('Aujourd\'hui')
                    ->query(fn (Builder $query): Builder => $query->whereDate('created_at', today())),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRefunds::route('/'),
        ];
    }
}

