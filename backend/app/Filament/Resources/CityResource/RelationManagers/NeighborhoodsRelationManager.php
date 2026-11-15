<?php

namespace App\Filament\Resources\CityResource\RelationManagers;

use App\Filament\Support\FilamentCityScope;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class NeighborhoodsRelationManager extends RelationManager
{
    protected static string $relationship = 'neighborhoods';

    protected static ?string $title = 'Quartiers & Zones de la Ville';

    protected static ?string $modelLabel = 'Quartier';

    protected static ?string $pluralModelLabel = 'Quartiers';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return FilamentCityScope::canAccessCityId((int) $ownerRecord->getKey());
    }

    protected function canCreate(): bool
    {
        return FilamentCityScope::canAccessCityId((int) $this->getOwnerRecord()->getKey());
    }

    protected function canEdit(Model $record): bool
    {
        return FilamentCityScope::canAccessCityId((int) $this->getOwnerRecord()->getKey());
    }

    protected function canDelete(Model $record): bool
    {
        return FilamentCityScope::canAccessCityId((int) $this->getOwnerRecord()->getKey());
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Nom du quartier')
                    ->required()
                    ->maxLength(120),

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
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nom du quartier')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Actif')
                    ->boolean(),

                Tables\Columns\TextColumn::make('distance_km')
                    ->label('Distance (km)')
                    ->suffix(' km')
                    ->placeholder('-'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Ajouter un Quartier'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Modifier'),
                Tables\Actions\DeleteAction::make()->label('Supprimer'),
            ]);
    }
}
