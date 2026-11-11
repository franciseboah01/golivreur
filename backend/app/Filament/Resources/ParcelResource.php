<?php

namespace App\Filament\Resources;

use App\Filament\Components\SearchableSelect;
use App\Filament\Resources\ParcelResource\Pages;
use App\Models\Courier;
use App\Models\Parcel;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ParcelResource extends Resource
{
    protected static ?string $model = Parcel::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Opérations GoColis';

    protected static ?string $navigationLabel = 'Colis & Expéditions';

    protected static ?string $modelLabel = 'Colis GoColis';

    protected static ?string $pluralModelLabel = 'Colis GoColis';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->hasHabilitationTab('parcels');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Expéditeur & Destinataire')
                    ->schema([
                        // Listes déroulantes remontées en haut avec recherche live et tri alphabétique
                        SearchableSelect::make('city_id')
                            ->label('Ville')
                            ->relationship('city', 'name', modifyQueryUsing: fn (Builder $query) => $query->orderBy('name'))
                            ->required(),
                        SearchableSelect::make('size')
                            ->label('Format du colis')
                            ->options(SearchableSelect::sortedOptions([
                                'grand' => 'Grand (Carton, colis volumineux)',
                                'moyen' => 'Moyen (Boîte à chaussures, vêtement)',
                                'petit' => 'Petit (Plis, documents, clés)',
                            ]))
                            ->required(),
                        SearchableSelect::make('sender_id')
                            ->label('Client Expéditeur')
                            ->relationship('sender', 'name', modifyQueryUsing: fn (Builder $query) => $query->orderBy('name'))
                            ->required(),
                        SearchableSelect::make('courier_id')
                            ->label('Livreur assigné')
                            ->relationship('courier', 'id', modifyQueryUsing: fn (Builder $query) => $query->with('user')->orderBy('id'))
                            ->getOptionLabelFromRecordUsing(fn (Courier $record) => ($record->user->name ?? "Livreur #{$record->id}").($record->user?->phone ? " ({$record->user->phone})" : '')),
                        Forms\Components\TextInput::make('recipient_name')
                            ->label('Nom du Destinataire')
                            ->required(),
                        Forms\Components\TextInput::make('recipient_phone')
                            ->label('Téléphone du Destinataire')
                            ->tel()
                            ->required(),
                    ])->columns(2),

                Forms\Components\Section::make('Tarification & Assurance (F CFA)')
                    ->schema([
                        // Liste déroulante en haut de section
                        SearchableSelect::make('payment_status')
                            ->label('Statut du paiement')
                            ->options(SearchableSelect::sortedOptions([
                                'failed' => 'Échoué',
                                'paid' => 'Payé',
                                'paid_cash' => 'Payé en espèces',
                                'pending' => 'En attente',
                                'pending_cash' => 'Espèces à encaisser',
                            ]))
                            ->required()
                            ->columnSpanFull(),
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
                    ])->columns(3),

                Forms\Components\Section::make('Itinéraire & Validation Sécurisée')
                    ->schema([
                        // Liste déroulante en haut de section
                        SearchableSelect::make('status')
                            ->label('Statut d\'acheminement')
                            ->options(SearchableSelect::sortedOptions([
                                'assigned' => 'Livreur en route vers la collecte',
                                'cancelled' => 'Annulé',
                                'delivered' => 'Colis livré avec succès',
                                'in_transit' => 'Colis récupéré - En transit',
                                'pending' => 'En attente de livreur',
                                'refused' => 'Refusé',
                                'waiting' => 'En attente de ramassage',
                            ]))
                            ->required()
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('pickup_address')
                            ->label('Adresse de collecte (Ramassage)')
                            ->required(),
                        Forms\Components\Textarea::make('delivery_address')
                            ->label('Adresse de livraison finale')
                            ->required(),
                        Forms\Components\Toggle::make('sender_code_locked')
                            ->label('Code Enlèvement Bloqué (3 erreurs)'),
                        Forms\Components\Toggle::make('recipient_code_locked')
                            ->label('Code Livraison Bloqué (3 erreurs)'),
                    ])->columns(2),

                Forms\Components\Section::make('Photos de Preuve (Enlèvement & Livraison)')
                    ->description('Clichés horodatés et géolocalisés pris par le livreur')
                    ->schema([
                        Forms\Components\Repeater::make('photos')
                            ->relationship('photos')
                            ->schema([
                                Forms\Components\TextInput::make('type')
                                    ->label('Étape (ramassage / livraison)')
                                    ->disabled(),
                                Forms\Components\TextInput::make('photo_url')
                                    ->label('Chemin de l\'image')
                                    ->disabled(),
                                Forms\Components\TextInput::make('created_at')
                                    ->label('Horodatage')
                                    ->disabled(),
                            ])
                            ->columns(3)
                            ->disabled(),
                    ]),
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

                Tables\Columns\TextColumn::make('size')
                    ->label('Format')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'petit', 'small' => 'Petit (Plis)',
                        'moyen', 'medium' => 'Moyen',
                        'grand', 'large' => 'Grand',
                        default => ucfirst($state),
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'gray',
                        'assigned', 'accepted', 'at_sender' => 'info',
                        'in_transit', 'picked_up' => 'warning',
                        'delivered' => 'success',
                        'cancelled', 'refused' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'En attente',
                        'assigned', 'accepted', 'at_sender' => 'Assigné',
                        'in_transit', 'picked_up' => 'En transit',
                        'delivered' => 'Livré',
                        'cancelled' => 'Annulé',
                        'refused' => 'Refusé',
                        default => $state,
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('shipping_fee')
                    ->label('Frais de Course')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' F CFA')
                    ->sortable(),

                Tables\Columns\TextColumn::make('declared_value')
                    ->label('Valeur Déclarée')
                    ->formatStateUsing(fn ($state) => (float) $state > 0 ? number_format((float) $state, 0, ',', ' ').' F CFA' : '-')
                    ->sortable(),

                Tables\Columns\TextColumn::make('insurance_amount')
                    ->label('Assurance')
                    ->formatStateUsing(fn ($state) => (float) $state > 0 ? number_format((float) $state, 0, ',', ' ').' F CFA' : 'Non assurée'),

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
                        'pending' => 'En attente de livreur',
                        'waiting' => 'En attente de ramassage',
                        'assigned' => 'Livreur assigné',
                        'in_transit' => 'En transit',
                        'delivered' => 'Livré avec succès',
                        'cancelled' => 'Annulé',
                        'refused' => 'Refusé',
                        'refunded' => 'Indemnisé / Remboursé',
                    ]),

                Tables\Filters\SelectFilter::make('size')
                    ->label('Format du Colis')
                    ->options([
                        'petit' => 'Petit (Plis, doc)',
                        'moyen' => 'Moyen (Colis standard)',
                        'grand' => 'Grand (Colis volumineux)',
                    ]),

                Tables\Filters\TernaryFilter::make('has_insurance')
                    ->label('Assurance Colis')
                    ->trueLabel('Colis Assurés')
                    ->falseLabel('Sans Assurance'),

                Tables\Filters\SelectFilter::make('payment_method')
                    ->label('Mode de Règlement')
                    ->options([
                        'cash' => 'Espèces',
                        'wave' => 'Wave',
                        'orange' => 'Orange Money',
                        'mtn' => 'MTN MoMo',
                    ]),

                Tables\Filters\TernaryFilter::make('sender_code_locked')
                    ->label('Code Enlèvement Bloqué')
                    ->trueLabel('Codes Bloqués')
                    ->falseLabel('Codes Normaux'),

                Tables\Filters\SelectFilter::make('city_id')
                    ->label('Filtrer par Ville')
                    ->relationship('city', 'name'),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('created_from')->label('Expédié à partir du'),
                        Forms\Components\DatePicker::make('created_until')->label('Expédié jusqu\'au'),
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

                Tables\Actions\Action::make('unlockSenderCode')
                    ->label('Débloquer Code Enlèvement')
                    ->icon('heroicon-m-lock-open')
                    ->color('success')
                    ->visible(fn (Parcel $record): bool => (bool) $record->sender_code_locked)
                    ->action(function (Parcel $record): void {
                        $record->update([
                            'sender_code_locked' => false,
                            'sender_code_attempts' => 0,
                            'sender_code_unlocked_by' => auth()->id(),
                        ]);

                        Notification::make()
                            ->title("Code expéditeur débloqué pour le colis #{$record->id}")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('unlockRecipientCode')
                    ->label('Débloquer Code Livraison')
                    ->icon('heroicon-m-lock-open')
                    ->color('success')
                    ->visible(fn (Parcel $record): bool => (bool) $record->recipient_code_locked)
                    ->action(function (Parcel $record): void {
                        $record->update([
                            'recipient_code_locked' => false,
                            'recipient_code_attempts' => 0,
                            'recipient_code_unlocked_by' => auth()->id(),
                        ]);

                        Notification::make()
                            ->title("Code destinataire débloqué pour le colis #{$record->id}")
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
            'index' => Pages\ListParcels::route('/'),
            'create' => Pages\CreateParcel::route('/create'),
            'edit' => Pages\EditParcel::route('/{record}/edit'),
        ];
    }
}
