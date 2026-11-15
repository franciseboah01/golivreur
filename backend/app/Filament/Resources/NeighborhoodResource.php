<?php

namespace App\Filament\Resources;

use App\Filament\Components\SearchableSelect;
use App\Filament\Resources\NeighborhoodResource\Pages;
use App\Filament\Support\FilamentCityScope;
use App\Models\Neighborhood;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

/**
 * Ressource Filament pour la gestion des Quartiers rattachés aux Villes.
 * Accessible depuis la section « Villes » (non affichée directement dans le menu latéral principal).
 */
class NeighborhoodResource extends Resource
{
    protected static ?string $model = Neighborhood::class;

    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Quartiers & Zones';

    protected static ?string $modelLabel = 'Quartier';

    protected static ?string $pluralModelLabel = 'Quartiers & Zones';

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->hasHabilitationTab('cities');
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
                Forms\Components\Section::make('Informations du Quartier')
                    ->schema([
                        FilamentCityScope::configureCitySelect(
                            SearchableSelect::make('city_id')->label('Ville')
                        )
                            ->required(),

                        Forms\Components\TextInput::make('name')
                            ->label('Nom du quartier')
                            ->required()
                            ->maxLength(120)
                            ->unique(
                                table: Neighborhood::class,
                                column: 'name',
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('city_id', $get('city_id')),
                            ),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Actif'),

                        Forms\Components\TextInput::make('distance_km')
                            ->label('Distance estimée (km)')
                            ->numeric()
                            ->minValue(0.1)
                            ->step(0.1)
                            ->nullable(),

                        Forms\Components\TextInput::make('lat')
                            ->label('Latitude GPS')
                            ->numeric()
                            ->minValue(-90.0)
                            ->maxValue(90.0)
                            ->step(0.000001)
                            ->nullable(),

                        Forms\Components\TextInput::make('lng')
                            ->label('Longitude GPS')
                            ->numeric()
                            ->minValue(-180.0)
                            ->maxValue(180.0)
                            ->step(0.000001)
                            ->nullable(),
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

                Tables\Columns\TextColumn::make('city.name')
                    ->label('Ville')
                    ->badge()
                    ->color('info')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Quartier')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Actif')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\TextColumn::make('distance_km')
                    ->label('Distance (km)')
                    ->suffix(' km')
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date d\'Ajout')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('name', 'asc')
            ->filters([
                Tables\Filters\SelectFilter::make('city_id')
                    ->label('Ville')
                    ->relationship('city', 'name', modifyQueryUsing: fn (Builder $query) => FilamentCityScope::scopeByCity($query, 'cities.id'))
                    ->visible(fn (): bool => ! FilamentCityScope::isLocal())
                    ->searchable()
                    ->preload(),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Statut')
                    ->trueLabel('Quartiers Actifs')
                    ->falseLabel('Quartiers Inactifs'),
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
            'index' => Pages\ListNeighborhoods::route('/'),
            'create' => Pages\CreateNeighborhood::route('/create'),
            'edit' => Pages\EditNeighborhood::route('/{record}/edit'),
        ];
    }
}
