<?php

namespace App\Filament\Resources;

use App\Enums\PaymentMethod;
use App\Filament\Components\SearchableSelect;
use App\Filament\Resources\OrderResource\Pages;
use App\Filament\Support\FilamentCityScope;
use App\Models\ActivityLog;
use App\Models\Courier;
use App\Models\Order;
use App\Models\User;
use App\Modules\GoShop\Services\AssignmentService;
use App\Modules\GoShop\Services\OrderService;
use App\Services\CourseOfferService;
use App\Services\NotificationService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationGroup = 'Opérations GoShop';

    protected static ?string $navigationLabel = 'Commandes GoShop';

    protected static ?string $modelLabel = 'Commande GoShop';

    protected static ?string $pluralModelLabel = 'Commandes GoShop';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->hasHabilitationTab('orders');
    }

    public static function canCreate(): bool
    {
        return FilamentCityScope::hasValidLocalCity() && parent::canCreate();
    }

    public static function canEdit(Model $record): bool
    {
        return FilamentCityScope::canAccessCityId($record->getAttribute('city_id') !== null ? (int) $record->getAttribute('city_id') : null)
            && parent::canEdit($record);
    }

    public static function canDelete(Model $record): bool
    {
        return FilamentCityScope::canAccessCityId($record->getAttribute('city_id') !== null ? (int) $record->getAttribute('city_id') : null)
            && parent::canDelete($record);
    }

    public static function getEloquentQuery(): Builder
    {
        return FilamentCityScope::scopeByCity(parent::getEloquentQuery());
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informations Générales')
                    ->description('Détails de la commande et intervenants')
                    ->schema([
                        // Listes déroulantes en haut avec recherche live et tri alphabétique
                        FilamentCityScope::configureCitySelect(
                            SearchableSelect::make('city_id')->label('Ville')
                        )
                            ->required(),
                        SearchableSelect::make('status')
                            ->label('Statut de la commande')
                            ->options(SearchableSelect::sortedOptions([
                                'accepted' => 'Acceptée',
                                'cancelled' => 'Annulée',
                                'pending' => 'En attente',
                                'waiting' => 'En attente de livreur',
                                'delivering' => 'En cours de livraison',
                                'preparing' => 'En préparation',
                                'delivered' => 'Livrée',
                                'ready' => 'Prête',
                            ]))
                            ->required()
                            ->default('pending'),
                        SearchableSelect::make('client_id')
                            ->label('Client')
                            ->relationship('client', 'name', modifyQueryUsing: fn (Builder $query) => FilamentCityScope::scopeByCity($query)->orderBy('name'))
                            ->required(),
                        SearchableSelect::make('merchant_id')
                            ->label('Commerce')
                            ->relationship('merchant', 'name', modifyQueryUsing: fn (Builder $query) => FilamentCityScope::scopeByCity($query)->orderBy('name'))
                            ->required(),
                        SearchableSelect::make('courier_id')
                            ->label('Livreur')
                            ->relationship('courier', 'id', modifyQueryUsing: fn (Builder $query) => FilamentCityScope::scopeByCity($query)->with('user')->orderBy('id'))
                            ->getOptionLabelFromRecordUsing(fn (Courier $record) => ($record->user->name ?? "Livreur #{$record->id}").($record->user?->phone ? " ({$record->user->phone})" : '')),
                    ])->columns(2),

                Forms\Components\Section::make('Détails Financiers (F CFA)')
                    ->schema([
                        // Listes déroulantes remontées en haut de la section
                        SearchableSelect::make('payment_method')
                            ->label('Mode de règlement')
                            ->options(SearchableSelect::sortedOptions(PaymentMethod::resourceOptions()))
                            ->required(),
                        SearchableSelect::make('payment_status')
                            ->label('Statut du paiement')
                            ->options(SearchableSelect::sortedOptions([
                                'amount_mismatch' => 'Écart de montant',
                                'failed' => 'Échoué',
                                'paid' => 'Payé',
                                'paid_cash' => 'Payé en espèces',
                                'pending' => 'En attente',
                                'pending_cash' => 'Espèces à encaisser',
                            ]))
                            ->required(),
                        Forms\Components\TextInput::make('payment_reference')
                            ->label('Référence de paiement'),
                        Forms\Components\TextInput::make('products_total')
                            ->label('Total articles (F CFA)')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('delivery_fee')
                            ->label('Frais de livraison (F CFA)')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('platform_commission')
                            ->label('Commission plateforme (F CFA)')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('merchant_amount')
                            ->label('Montant reversé au commerce (F CFA)')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('courier_amount')
                            ->label('Rémunération livreur (F CFA)')
                            ->numeric()
                            ->required(),
                    ])->columns(3),

                Forms\Components\Section::make('Sécurité & Livraison')
                    ->schema([
                        Forms\Components\Textarea::make('delivery_address')
                            ->label('Adresse complète de livraison')
                            ->required()
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('admin_confirmation_code')
                            ->label('Code OTP de livraison (Client → Livreur)')
                            ->formatStateUsing(fn (?Order $record): string => $record !== null && $record->admin_confirmation_code !== null ? $record->admin_confirmation_code : 'Non disponible (régénérer)')
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\Toggle::make('confirmation_locked')
                            ->label('Code bloqué après 3 tentatives erronées'),
                        Forms\Components\Toggle::make('rain_mode_applied')
                            ->label('Tarif Mode Pluie appliqué (+20%)'),
                        Forms\Components\Toggle::make('peak_hours_applied')
                            ->label('Tarif Heures de Pointe appliqué (+15%)'),
                        Forms\Components\Textarea::make('special_instructions')
                            ->label('Instructions spéciales')
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('cancel_reason')
                            ->label('Motif d\'annulation en cas d\'échec')
                            ->columnSpanFull(),
                    ])->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('rowIndex')
                    ->rowIndex()
                    ->label('N°'),

                Tables\Columns\TextColumn::make('id')
                    ->label('Réf ID')
                    ->sortable(),

                Tables\Columns\TextColumn::make('client.name')
                    ->label('Client')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('merchant.name')
                    ->label('Commerce')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('courier.user.name')
                    ->label('Livreur')
                    ->placeholder('Non assigné')
                    ->sortable(),

                Tables\Columns\TextColumn::make('city.name')
                    ->label('Ville')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'gray',
                        'accepted' => 'info',
                        'waiting', 'preparing' => 'warning',
                        'ready' => 'primary',
                        'delivering' => 'info',
                        'delivered' => 'success',
                        'cancelled' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'En attente',
                        'accepted' => 'Acceptée',
                        'waiting' => 'En attente de livreur',
                        'preparing' => 'En préparation',
                        'ready' => 'Prête',
                        'delivering' => 'En livraison',
                        'delivered' => 'Livrée',
                        'cancelled' => 'Annulée',
                        default => $state,
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('products_total')
                    ->label('Articles')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' F CFA')
                    ->sortable(),

                Tables\Columns\TextColumn::make('delivery_fee')
                    ->label('Livraison')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' F CFA')
                    ->sortable(),

                Tables\Columns\TextColumn::make('platform_commission')
                    ->label('Commission')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' F CFA')
                    ->sortable(),

                Tables\Columns\TextColumn::make('payment_method')
                    ->label('Paiement')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => PaymentMethod::tryFromInput($state)?->shortLabel() ?? (string) $state),

                Tables\Columns\TextColumn::make('payment_status')
                    ->label('État Paiement')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'paid' => 'success',
                        'pending' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'paid' => 'Payé',
                        'pending' => 'En attente',
                        'failed' => 'Échoué',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('admin_confirmation_code')
                    ->label('OTP Livraison')
                    ->badge()
                    ->color('primary')
                    ->fontFamily('mono')
                    ->copyable()
                    ->placeholder('—'),

                Tables\Columns\IconColumn::make('confirmation_locked')
                    ->label('Code Bloqué')
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->falseIcon('heroicon-o-lock-open')
                    ->trueColor('danger')
                    ->falseColor('gray'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Statut de la Commande')
                    ->options([
                        'pending' => 'En attente',
                        'accepted' => 'Acceptée',
                        'waiting' => 'En attente de livreur',
                        'preparing' => 'En préparation',
                        'ready' => 'Prête',
                        'delivering' => 'En cours de livraison',
                        'delivered' => 'Livrée',
                        'cancelled' => 'Annulée',
                    ]),
                Tables\Filters\SelectFilter::make('payment_method')
                    ->label('Mode de Règlement')
                    ->options(PaymentMethod::resourceOptions()),
                Tables\Filters\SelectFilter::make('payment_status')
                    ->label('État du Paiement')
                    ->options([
                        'paid' => 'Payé',
                        'pending' => 'En attente',
                        'failed' => 'Échoué',
                    ]),
                Tables\Filters\TernaryFilter::make('confirmation_locked')
                    ->label('Sécurité Code OTP')
                    ->trueLabel('Codes Bloqués (Support requis)')
                    ->falseLabel('Codes Normaux'),
                Tables\Filters\SelectFilter::make('city_id')
                    ->label('Filtrer par Ville')
                    ->relationship('city', 'name', modifyQueryUsing: fn (Builder $query) => FilamentCityScope::scopeByCity($query, 'cities.id'))
                    ->visible(fn (): bool => ! FilamentCityScope::isLocal()),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('created_from')->label('Commandé à partir du'),
                        Forms\Components\DatePicker::make('created_until')->label('Commandé jusqu\'au'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn ($query, $date) => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn ($query, $date) => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Détail'),

                Tables\Actions\Action::make('cancelOrder')
                    ->label('Annuler')
                    ->icon('heroicon-m-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Order $record): string => "Annulation de la commande #{$record->id}")
                    ->form([
                        Forms\Components\Textarea::make('cancellation_reason')
                            ->label('Motif d\'annulation obligatoire')
                            ->required(),
                    ])
                    ->visible(fn (Order $record): bool => ! in_array($record->status, ['delivered', 'cancelled'], true))
                    ->action(function (Order $record, array $data): void {
                        FilamentCityScope::authorizeRecord($record);

                        $record->update([
                            'status' => 'cancelled',
                            'cancel_reason' => $data['cancellation_reason'],
                            'cancelled_at' => now(),
                        ]);
                        app(OrderService::class)->restaurerStockCommande($record);

                        ActivityLog::create([
                            'user_id' => auth()->id(),
                            'action' => 'order_cancelled_by_admin',
                            'entity_type' => 'order',
                            'entity_id' => $record->id,
                            'properties' => ['reason' => $data['cancellation_reason']],
                            'ip_address' => request()->ip(),
                            'severity' => 'warning',
                        ]);

                        Notification::make()
                            ->title("Commande #{$record->id} annulée")
                            ->danger()
                            ->send();
                    }),

                Tables\Actions\Action::make('reassignCourier')
                    ->label('Réassigner')
                    ->icon('heroicon-m-arrow-path')
                    ->color('warning')
                    ->form(fn (Order $record): array => [
                        Forms\Components\Select::make('courier_id')
                            ->label('Nouveau livreur assigné')
                            ->options(fn () => Courier::where('is_active', true)
                                ->where('city_id', $record->city_id)
                                ->with('user')
                                ->get()
                                ->mapWithKeys(
                                    fn ($courier) => [$courier->id => ($courier->user->name ?? "Livreur #{$courier->id}").($courier->vehicle_plate ? " ({$courier->vehicle_plate})" : '')]
                                ))
                            ->searchable()
                            ->required(),
                    ])
                    ->visible(fn (Order $record): bool => ! in_array($record->status, ['delivered', 'cancelled'], true))
                    ->action(function (Order $record, array $data): void {
                        FilamentCityScope::authorizeRecord($record);

                        $courier = Courier::where('id', (int) $data['courier_id'])
                            ->where('is_active', true)
                            ->where('city_id', $record->city_id)
                            ->first();

                        if (! $courier) {
                            Notification::make()
                                ->title('Livreur invalide ou hors de la ville de la commande.')
                                ->danger()
                                ->send();

                            return;
                        }

                        /** @var User $admin */
                        $admin = auth()->user();
                        app(AssignmentService::class)->assignerManuellementParAdmin($record, $courier, $admin);

                        Notification::make()
                            ->title("Commande #{$record->id} réassignée")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('unlockCode')
                    ->label('Débloquer code')
                    ->icon('heroicon-m-lock-open')
                    ->color('success')
                    ->visible(fn (Order $record): bool => (bool) $record->confirmation_locked)
                    ->action(function (Order $record): void {
                        FilamentCityScope::authorizeRecord($record);

                        $record->update([
                            'confirmation_locked' => false,
                            'confirmation_attempts' => 0,
                            'confirmation_unlocked_by' => auth()->id(),
                        ]);

                        Notification::make()
                            ->title("Code débloqué pour commande #{$record->id}")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('regenerateCode')
                    ->label('Régénérer code')
                    ->icon('heroicon-m-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Régénérer le code de confirmation')
                    ->modalDescription('Un nouveau code à 4 chiffres chiffré sera généré pour le client. L\'action est tracée dans les journaux d\'audit.')
                    ->visible(function (Order $record): bool {
                        $user = auth()->user();

                        return $user instanceof User
                            && $user->canManageCodes()
                            && $user->canAccessCity((int) $record->city_id)
                            && ! in_array($record->status, [
                                Order::STATUS_DELIVERED,
                                Order::STATUS_CANCELLED,
                                Order::STATUS_REFUSED,
                                Order::STATUS_DISPUTED,
                            ], true);
                    })
                    ->action(function (Order $record): void {
                        $user = auth()->user();
                        if (! $user instanceof User || ! $user->canManageCodes() || ! $user->canAccessCity((int) $record->city_id)) {
                            abort(403);
                        }

                        app(OrderService::class)->regenererCodeConfirmation($record, $user);

                        Notification::make()
                            ->title("Nouveau code généré pour commande #{$record->id}")
                            ->success()
                            ->send();
                    }),

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
            'index' => Pages\ListOrders::route('/'),
            'create' => Pages\CreateOrder::route('/create'),
            'edit' => Pages\EditOrder::route('/{record}/edit'),
        ];
    }
}
