<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RefundResource\Pages;
use App\Models\Refund;
use App\Models\User;
use App\Services\RefundService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ressource Filament dédiée aux Remboursements & Indemnisations clients (table refunds).
 * Source unique des plafonds : SettingsService / RefundService (CDC 8.5).
 */
class RefundResource extends Resource
{
    protected static ?string $model = Refund::class;

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
        $user = auth()->user();
        $query = parent::getEloquentQuery()
            ->with(['city', 'order', 'parcel', 'client'])
            ->latest();

        if ($user && in_array($user->role, RefundService::LOCAL_ROLES, true) && $user->city_id) {
            $query->where('city_id', $user->city_id);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('id')
                    ->label('N° Remboursement')
                    ->disabled(),
                Forms\Components\TextInput::make('amount')
                    ->label('Montant (F CFA)')
                    ->disabled(),
                Forms\Components\TextInput::make('status')
                    ->label('Statut')
                    ->disabled(),
                Forms\Components\TextInput::make('cost_bearer')
                    ->label('Imputation')
                    ->disabled(),
                Forms\Components\TextInput::make('payment_reference')
                    ->label('Référence Mobile Money')
                    ->disabled(),
                Forms\Components\Textarea::make('reason')
                    ->label('Motif du remboursement')
                    ->disabled()
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('Réf.')
                    ->formatStateUsing(fn ($state): string => "#{$state}")
                    ->weight('bold')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date & Heure')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('city.name')
                    ->label('Ville')
                    ->sortable(),

                Tables\Columns\TextColumn::make('client.name')
                    ->label('Client')
                    ->searchable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Montant')
                    ->money('XOF')
                    ->color('danger')
                    ->weight('bold')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Refund::STATUS_REQUESTED => 'warning',
                        Refund::STATUS_APPROVED => 'info',
                        Refund::STATUS_PAID => 'success',
                        Refund::STATUS_REJECTED => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Refund::STATUS_REQUESTED => 'Demandé',
                        Refund::STATUS_APPROVED => 'Approuvé',
                        Refund::STATUS_PAID => 'Payé',
                        Refund::STATUS_REJECTED => 'Rejeté',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('cost_bearer')
                    ->label('Support financier')
                    ->badge(),

                Tables\Columns\TextColumn::make('payment_reference')
                    ->label('Réf. Paiement')
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Motif')
                    ->limit(40)
                    ->searchable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        Refund::STATUS_REQUESTED => 'Demandé',
                        Refund::STATUS_APPROVED => 'Approuvé',
                        Refund::STATUS_PAID => 'Payé',
                        Refund::STATUS_REJECTED => 'Rejeté',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Approuver')
                    ->icon('heroicon-m-check-circle')
                    ->color('info')
                    ->visible(fn (Refund $record): bool => $record->status === Refund::STATUS_REQUESTED)
                    ->action(function (Refund $record): void {
                        /** @var User $user */
                        $user = auth()->user();
                        $res = app(RefundService::class)->approuverRemboursement($record, $user);

                        if (! $res['ok']) {
                            Notification::make()->title($res['message'] ?? 'Erreur')->danger()->send();

                            return;
                        }

                        Notification::make()->title('Remboursement approuvé')->success()->send();
                    }),

                Tables\Actions\Action::make('markPaid')
                    ->label('Marquer payé')
                    ->icon('heroicon-m-banknotes')
                    ->color('success')
                    ->visible(fn (Refund $record): bool => $record->status === Refund::STATUS_APPROVED)
                    ->form([
                        Forms\Components\TextInput::make('payment_reference')
                            ->label('Référence virement Mobile Money')
                            ->required()
                            ->maxLength(100),
                    ])
                    ->action(function (Refund $record, array $data): void {
                        /** @var User $user */
                        $user = auth()->user();
                        $res = app(RefundService::class)->marquerPayeRemboursement($record, $user, (string) $data['payment_reference']);

                        if (! $res['ok']) {
                            Notification::make()->title($res['message'] ?? 'Erreur')->danger()->send();

                            return;
                        }

                        Notification::make()->title('Remboursement marqué payé')->success()->send();
                    }),

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
