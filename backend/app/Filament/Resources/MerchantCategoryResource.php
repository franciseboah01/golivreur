<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MerchantCategoryResource\Pages;
use App\Models\MerchantCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class MerchantCategoryResource extends Resource
{
    protected static ?string $model = MerchantCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Partenaires';

    protected static ?string $navigationLabel = 'Catégories d\'Activité';

    protected static ?string $modelLabel = 'Catégorie d\'Activité';

    protected static ?string $pluralModelLabel = 'Catégories d\'Activité';

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        $user = auth()->user();
        return $user !== null && $user->hasHabilitationTab('merchants');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informations de la Catégorie')
                    ->description('Définissez une catégorie commerciale pour regrouper les commerces partenaires')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nom de la catégorie')
                            ->required()
                            ->maxLength(100)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (string $operation, $state, Set $set) {
                                if ($operation === 'create') {
                                    $set('slug', Str::slug($state));
                                }
                            }),

                        Forms\Components\TextInput::make('slug')
                            ->label('Identifiant système (slug)')
                            ->required()
                            ->maxLength(100)
                            ->unique(MerchantCategory::class, 'slug', ignoreRecord: true),

                        Forms\Components\Textarea::make('description')
                            ->label('Description & Types de produits')
                            ->placeholder('Ex: Plats chauds, maquis traditionnels, fast-food et boissons fraîches')
                            ->columnSpanFull(),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Catégorie active (visible lors de l\'enregistrement des commerces)')
                            ->default(true),
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

                Tables\Columns\TextColumn::make('name')
                    ->label('Nom de la Catégorie')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('slug')
                    ->label('Identifiant (slug)')
                    ->badge()
                    ->color('gray')
                    ->searchable(),

                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->limit(45)
                    ->placeholder('Aucune description'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\TextColumn::make('merchants_count')
                    ->counts('merchants')
                    ->label('Commerces affiliés')
                    ->badge()
                    ->color('info')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créée le')
                    ->dateTime('d/m/Y')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'asc')
            ->defaultKeySort(true)
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Statut')
                    ->trueLabel('Catégories Actives')
                    ->falseLabel('Catégories Inactives'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Modifier'),
                Tables\Actions\DeleteAction::make()->label('Supprimer'),
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
            'index' => Pages\ListMerchantCategories::route('/'),
            'create' => Pages\CreateMerchantCategory::route('/create'),
            'edit' => Pages\EditMerchantCategory::route('/{record}/edit'),
        ];
    }
}

