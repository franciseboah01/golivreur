<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CityResource\Pages;
use App\Filament\Resources\CityResource\RelationManagers;
use App\Models\City;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CityResource extends Resource
{
    protected static ?string $model = City::class;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $modelLabel = 'Ville';

    protected static ?string $pluralModelLabel = 'Villes & Mode Urgence';

    protected static ?int $navigationSort = 7;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(100),
                Forms\Components\Toggle::make('is_active')
                    ->required(),
                Forms\Components\TextInput::make('center_lat')
                    ->numeric(),
                Forms\Components\TextInput::make('center_lng')
                    ->numeric(),
                Forms\Components\Toggle::make('rain_mode_active')
                    ->required(),
                Forms\Components\DateTimePicker::make('rain_mode_activated_at'),
                Forms\Components\Toggle::make('emergency_mode_active')
                    ->required(),
                Forms\Components\Textarea::make('emergency_mode_message')
                    ->columnSpanFull(),
                Forms\Components\DateTimePicker::make('emergency_mode_activated_at'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('center_lat')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('center_lng')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\IconColumn::make('rain_mode_active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('rain_mode_activated_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\IconColumn::make('emergency_mode_active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('emergency_mode_activated_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCities::route('/'),
            'create' => Pages\CreateCity::route('/create'),
            'edit' => Pages\EditCity::route('/{record}/edit'),
        ];
    }
}
