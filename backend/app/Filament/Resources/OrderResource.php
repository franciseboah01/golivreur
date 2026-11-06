<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Models\City;
use App\Models\Order;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationGroup = 'Opérations GoShop';

    protected static ?string $navigationLabel = 'Commandes GoShop';

    protected static ?string $modelLabel = 'Commande GoShop';

    protected static ?string $pluralModelLabel = 'Commandes GoShop';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informations Générales')
                    ->description('Détails de la commande et intervenants')
                    ->schema([
                        Forms\Components\Select::make('client_id')
                            ->label('Client')
                            ->relationship('client', 'name')
                            ->searchable()
                            ->required(),
                        Forms\Components\Select::make('merchant_id')
                            ->label('Commerce')
                            ->relationship('merchant', 'name')
                            ->searchable()
                            ->required(),
                        Forms\Components\Select::make('courier_id')
                            ->label('Livreur')
                            ->relationship('courier', 'id')
                            ->searchable(),
                        Forms\Components\Select::make('city_id')
                            ->label('Ville')
                            ->relationship('city', 'name')
                            ->required(),
                        Forms\Components\Select::make('status')
                            ->label('Statut de la commande')
                            ->options([
                                'pending' => 'En attente',
                                'accepted' => 'Acceptée',
                                'preparing' => 'En préparation',
                                'ready' => 'Prête',
                                'delivering' => 'En cours de livraison',
                                'delivered' => 'Livrée',
                                'cancelled' => 'Annulée',
                            ])
                            ->required()
                            ->default('pending'),
                    ])->columns(2),

                Forms\Components\Section::make('Détails Financiers (F CFA)')
                    ->schema([
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
                        Forms\Components\Select::make('payment_method')
                            ->label('Mode de règlement')
                            ->options([
                                'cash' => 'Espèces à la livraison',
                                'wallet' => 'Portefeuille GoLivreur',
                                'wave' => 'Wave Mobile Money',
                                'orange' => 'Orange Money',
                                'mtn' => 'MTN Mobile Money',
                                'moov' => 'Moov Money',
                            ])
                            ->required(),
                        Forms\Components\Select::make('payment_status')
                            ->label('Statut du paiement')
                            ->options([
                                'pending' => 'En attente',
                                'paid' => 'Payé',
                                'failed' => 'Échoué',
                            ])
                            ->required(),
                        Forms\Components\TextInput::make('payment_reference')
                            ->label('Référence de paiement'),
                    ])->columns(3),

                Forms\Components\Section::make('Sécurité & Livraison')
                    ->schema([
                        Forms\Components\Textarea::make('delivery_address')
                            ->label('Adresse complète de livraison')
                            ->required()
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('confirmation_code')
                            ->label('Code OTP de confirmation (4 chiffres)')
                            ->maxLength(10),
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
                        'preparing' => 'warning',
                        'ready' => 'primary',
                        'delivering' => 'info',
                        'delivered' => 'success',
                        'cancelled' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'En attente',
                        'accepted' => 'Acceptée',
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
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ') . ' F CFA')
                    ->sortable(),

                Tables\Columns\TextColumn::make('delivery_fee')
                    ->label('Livraison')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ') . ' F CFA')
                    ->sortable(),

                Tables\Columns\TextColumn::make('platform_commission')
                    ->label('Commission')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ') . ' F CFA')
                    ->sortable(),

                Tables\Columns\TextColumn::make('payment_method')
                    ->label('Paiement')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'cash' => 'Espèces',
                        'wallet' => 'Portefeuille',
                        'wave' => 'Wave',
                        'orange' => 'Orange',
                        'mtn' => 'MTN',
                        'moov' => 'Moov',
                        default => $state,
                    }),

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
                        'preparing' => 'En préparation',
                        'ready' => 'Prête',
                        'delivering' => 'En cours de livraison',
                        'delivered' => 'Livrée',
                        'cancelled' => 'Annulée',
                    ]),
                Tables\Filters\SelectFilter::make('payment_method')
                    ->label('Mode de Règlement')
                    ->options([
                        'cash' => 'Espèces à la livraison',
                        'wave' => 'Wave Mobile Money',
                        'orange' => 'Orange Money',
                        'mtn' => 'MTN Mobile Money',
                        'moov' => 'Moov Money',
                        'wallet' => 'Portefeuille GoLivreur',
                    ]),
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
                    ->relationship('city', 'name'),
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
