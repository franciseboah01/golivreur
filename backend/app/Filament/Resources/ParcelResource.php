<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ParcelResource\Pages;
use App\Models\Parcel;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ParcelResource extends Resource
{
    protected static ?string $model = Parcel::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Opérations GoColis';

    protected static ?string $navigationLabel = 'Colis & Expéditions';

    protected static ?string $modelLabel = 'Colis GoColis';

    protected static ?string $pluralModelLabel = 'Colis GoColis';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Expéditeur & Destinataire')
                    ->schema([
                        Forms\Components\Select::make('sender_id')
                            ->label('Client Expéditeur')
                            ->relationship('sender', 'name')
                            ->searchable()
                            ->required(),
                        Forms\Components\Select::make('courier_id')
                            ->label('Livreur assigné')
                            ->relationship('courier', 'id')
                            ->searchable(),
                        Forms\Components\Select::make('city_id')
                            ->label('Ville')
                            ->relationship('city', 'name')
                            ->required(),
                        Forms\Components\TextInput::make('recipient_name')
                            ->label('Nom du Destinataire')
                            ->required(),
                        Forms\Components\TextInput::make('recipient_phone')
                            ->label('Téléphone du Destinataire')
                            ->tel()
                            ->required(),
                        Forms\Components\Select::make('package_size')
                            ->label('Format du colis')
                            ->options([
                                'small' => 'Petit (Plis, documents, clés)',
                                'medium' => 'Moyen (Boîte à chaussures, vêtement)',
                                'large' => 'Grand (Carton, colis volumineux)',
                            ])
                            ->required(),
                    ])->columns(2),

                Forms\Components\Section::make('Tarification & Assurance (F CFA)')
                    ->schema([
                        Forms\Components\TextInput::make('shipping_fee')
                            ->label('Frais de livraison (F CFA)')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('declared_value')
                            ->label('Valeur déclarée (F CFA)')
                            ->numeric(),
                        Forms\Components\TextInput::make('insurance_amount')
                            ->label('Prime d\'assurance (F CFA)')
                            ->numeric(),
                        Forms\Components\TextInput::make('platform_commission')
                            ->label('Commission plateforme (F CFA)')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('courier_amount')
                            ->label('Rémunération livreur (F CFA)')
                            ->numeric()
                            ->required(),
                        Forms\Components\Select::make('payment_status')
                            ->label('Statut du paiement')
                            ->options([
                                'pending' => 'En attente',
                                'paid' => 'Payé',
                                'failed' => 'Échoué',
                            ])
                            ->required(),
                    ])->columns(3),

                Forms\Components\Section::make('Itinéraire & Validation Sécurisée')
                    ->schema([
                        Forms\Components\Textarea::make('pickup_address')
                            ->label('Adresse de collecte (Ramassage)')
                            ->required(),
                        Forms\Components\Textarea::make('delivery_address')
                            ->label('Adresse de livraison finale')
                            ->required(),
                        Forms\Components\Select::make('status')
                            ->label('Statut d\'acheminement')
                            ->options([
                                'pending' => 'En attente de livreur',
                                'assigned' => 'Livreur en route vers la collecte',
                                'in_transit' => 'Colis récupéré - En transit',
                                'delivered' => 'Colis livré avec succès',
                                'cancelled' => 'Annulé',
                                'returned' => 'Retourné à l\'expéditeur',
                            ])
                            ->required(),
                        Forms\Components\Toggle::make('sender_code_locked')
                            ->label('Code Enlèvement Bloqué (3 erreurs)'),
                        Forms\Components\Toggle::make('recipient_code_locked')
                            ->label('Code Livraison Bloqué (3 erreurs)'),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('N°')
                    ->sortable(),

                Tables\Columns\TextColumn::make('sender.name')
                    ->label('Expéditeur')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('recipient_name')
                    ->label('Destinataire')
                    ->searchable(),

                Tables\Columns\TextColumn::make('recipient_phone')
                    ->label('Tél. Destinataire')
                    ->searchable(),

                Tables\Columns\TextColumn::make('courier.user.name')
                    ->label('Livreur')
                    ->placeholder('Non assigné')
                    ->sortable(),

                Tables\Columns\TextColumn::make('city.name')
                    ->label('Ville')
                    ->sortable(),

                Tables\Columns\TextColumn::make('package_size')
                    ->label('Format')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'small' => 'Petit',
                        'medium' => 'Moyen',
                        'large' => 'Grand',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'gray',
                        'assigned' => 'info',
                        'in_transit' => 'warning',
                        'delivered' => 'success',
                        'cancelled' => 'danger',
                        'returned' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'En attente',
                        'assigned' => 'Assigné',
                        'in_transit' => 'En transit',
                        'delivered' => 'Livré',
                        'cancelled' => 'Annulé',
                        'returned' => 'Retourné',
                        default => $state,
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('shipping_fee')
                    ->label('Frais de Course')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ') . ' F CFA')
                    ->sortable(),

                Tables\Columns\TextColumn::make('declared_value')
                    ->label('Valeur Déclarée')
                    ->formatStateUsing(fn ($state) => $state ? number_format((float) $state, 0, ',', ' ') . ' F CFA' : '-')
                    ->sortable(),

                Tables\Columns\TextColumn::make('insurance_amount')
                    ->label('Assurance')
                    ->formatStateUsing(fn ($state) => $state ? number_format((float) $state, 0, ',', ' ') . ' F CFA' : 'Non assurée'),

                Tables\Columns\IconColumn::make('sender_code_locked')
                    ->label('Enlèv. Bloqué')
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->falseIcon('heroicon-o-lock-open')
                    ->trueColor('danger')
                    ->falseColor('gray'),

                Tables\Columns\IconColumn::make('recipient_code_locked')
                    ->label('Livrais. Bloqué')
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
                    ->label('Filtrer par Statut')
                    ->options([
                        'pending' => 'En attente',
                        'assigned' => 'Assigné',
                        'in_transit' => 'En transit',
                        'delivered' => 'Livré',
                        'cancelled' => 'Annulé',
                        'returned' => 'Retourné',
                    ]),
                Tables\Filters\SelectFilter::make('package_size')
                    ->label('Filtrer par Format')
                    ->options([
                        'small' => 'Petit (Plis, doc)',
                        'medium' => 'Moyen (Colis moyen)',
                        'large' => 'Grand (Colis lourd/volumineux)',
                    ]),
                Tables\Filters\SelectFilter::make('city_id')
                    ->label('Filtrer par Ville')
                    ->relationship('city', 'name'),
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
            'index' => Pages\ListParcels::route('/'),
            'create' => Pages\CreateParcel::route('/create'),
            'edit' => Pages\EditParcel::route('/{record}/edit'),
        ];
    }
}
