<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MerchantResource\Pages;
use App\Models\Merchant;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class MerchantResource extends Resource
{
    protected static ?string $model = Merchant::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationGroup = 'Partenaires';

    protected static ?string $navigationLabel = 'Commerces Partenaires';

    protected static ?string $modelLabel = 'Commerce Partenaire';

    protected static ?string $pluralModelLabel = 'Commerces Partenaires';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Identité du Commerce')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nom de l\'enseigne')
                            ->required()
                            ->maxLength(100),
                        Forms\Components\Select::make('user_id')
                            ->label('Compte Gestionnaire')
                            ->relationship('user', 'name')
                            ->searchable()
                            ->required(),
                        Forms\Components\Select::make('city_id')
                            ->label('Ville d\'implantation')
                            ->relationship('city', 'name')
                            ->required(),
                        Forms\Components\Select::make('category')
                            ->label('Catégorie d\'activité')
                            ->options([
                                'restaurant' => 'Restaurant / Fast-Food',
                                'pharmacie' => 'Pharmacie & Parapharmacie',
                                'supermarche' => 'Supermarché & Épicerie',
                                'boulangerie' => 'Boulangerie & Pâtisserie',
                                'boutique' => 'Mode & Accessoires',
                                'autre' => 'Autre Commerce',
                            ])
                            ->required(),
                        Forms\Components\Textarea::make('address')
                            ->label('Adresse physique')
                            ->required()
                            ->columnSpanFull(),
                    ])->columns(2),

                Forms\Components\Section::make('Paramètres d\'Exploitation & Commission')
                    ->schema([
                        Forms\Components\TextInput::make('commission_rate')
                            ->label('Taux de commission plateforme (%)')
                            ->numeric()
                            ->default(15)
                            ->required(),
                        Forms\Components\TextInput::make('prep_time_minutes')
                            ->label('Temps de préparation moyen (minutes)')
                            ->numeric()
                            ->default(20)
                            ->required(),
                        Forms\Components\Toggle::make('is_open')
                            ->label('Commerce actuellement ouvert aux commandes'),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Compte approuvé et actif'),
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

                Tables\Columns\TextColumn::make('name')
                    ->label('Enseigne')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Gestionnaire')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.phone')
                    ->label('Téléphone')
                    ->searchable(),

                Tables\Columns\TextColumn::make('city.name')
                    ->label('Ville')
                    ->sortable(),

                Tables\Columns\TextColumn::make('category')
                    ->label('Catégorie')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'restaurant' => 'Restaurant',
                        'pharmacie' => 'Pharmacie',
                        'supermarche' => 'Supermarché',
                        'boulangerie' => 'Boulangerie',
                        'boutique' => 'Boutique',
                        default => ucfirst($state),
                    }),

                Tables\Columns\IconColumn::make('is_open')
                    ->label('Ouvert')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Actif')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-badge')
                    ->falseIcon('heroicon-o-minus-circle')
                    ->trueColor('success')
                    ->falseColor('gray'),

                Tables\Columns\TextColumn::make('commission_rate')
                    ->label('Commission')
                    ->formatStateUsing(fn ($state) => $state . ' %')
                    ->sortable(),

                Tables\Columns\TextColumn::make('prep_time_minutes')
                    ->label('Préparation')
                    ->formatStateUsing(fn ($state) => $state . ' min')
                    ->sortable(),

                Tables\Columns\TextColumn::make('average_rating')
                    ->label('Note')
                    ->formatStateUsing(fn ($state) => '★ ' . number_format((float) $state, 1))
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_ratings')
                    ->label('Avis')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date Inscription')
                    ->dateTime('d/m/Y')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_open')
                    ->label('Statut Ouverture'),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Statut Validation'),
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
            'index' => Pages\ListMerchants::route('/'),
            'create' => Pages\CreateMerchant::route('/create'),
            'edit' => Pages\EditMerchant::route('/{record}/edit'),
        ];
    }
}
