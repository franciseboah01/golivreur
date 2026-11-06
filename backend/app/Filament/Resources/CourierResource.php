<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CourierResource\Pages;
use App\Filament\Resources\CourierResource\RelationManagers;
use App\Models\Courier;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CourierResource extends Resource
{
    protected static ?string $model = Courier::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Partenaires';

    protected static ?string $modelLabel = 'Livreur';

    protected static ?string $pluralModelLabel = 'Livreurs';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('user_id')
                    ->relationship('user', 'name')
                    ->required(),
                Forms\Components\Select::make('city_id')
                    ->relationship('city', 'name')
                    ->required(),
                Forms\Components\Toggle::make('is_online')
                    ->required(),
                Forms\Components\TextInput::make('current_lat')
                    ->numeric(),
                Forms\Components\TextInput::make('current_lng')
                    ->numeric(),
                Forms\Components\DateTimePicker::make('location_updated_at'),
                Forms\Components\TextInput::make('vehicle_type')
                    ->required()
                    ->maxLength(50)
                    ->default('moto'),
                Forms\Components\TextInput::make('vehicle_plate')
                    ->maxLength(20),
                Forms\Components\Toggle::make('is_active')
                    ->required(),
                Forms\Components\DateTimePicker::make('activated_at'),
                Forms\Components\TextInput::make('activated_by')
                    ->numeric(),
                Forms\Components\TextInput::make('id_card_number')
                    ->maxLength(50),
                Forms\Components\TextInput::make('id_card_photo_url')
                    ->maxLength(500),
                Forms\Components\TextInput::make('photo_url')
                    ->maxLength(500),
                Forms\Components\TextInput::make('consecutive_refusals')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\DateTimePicker::make('penalty_until'),
                Forms\Components\TextInput::make('average_rating')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('total_ratings')
                    ->required()
                    ->numeric()
                    ->default(0),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('city.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_online')
                    ->boolean(),
                Tables\Columns\TextColumn::make('current_lat')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('current_lng')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('location_updated_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('vehicle_type')
                    ->searchable(),
                Tables\Columns\TextColumn::make('vehicle_plate')
                    ->searchable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('activated_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('activated_by')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('id_card_number')
                    ->searchable(),
                Tables\Columns\TextColumn::make('id_card_photo_url')
                    ->searchable(),
                Tables\Columns\TextColumn::make('photo_url')
                    ->searchable(),
                Tables\Columns\TextColumn::make('consecutive_refusals')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('penalty_until')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('average_rating')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_ratings')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
            'index' => Pages\ListCouriers::route('/'),
            'create' => Pages\CreateCourier::route('/create'),
            'edit' => Pages\EditCourier::route('/{record}/edit'),
        ];
    }
}
