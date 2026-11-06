<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ParcelResource\Pages;
use App\Filament\Resources\ParcelResource\RelationManagers;
use App\Models\Parcel;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ParcelResource extends Resource
{
    protected static ?string $model = Parcel::class;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationGroup = 'Opérations GoColis';

    protected static ?string $modelLabel = 'Expédition GoColis';

    protected static ?string $pluralModelLabel = 'Expéditions GoColis';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('sender_id')
                    ->relationship('sender', 'name')
                    ->required(),
                Forms\Components\Select::make('courier_id')
                    ->relationship('courier', 'id'),
                Forms\Components\Select::make('city_id')
                    ->relationship('city', 'name')
                    ->required(),
                Forms\Components\TextInput::make('status')
                    ->required()
                    ->maxLength(30)
                    ->default('pending'),
                Forms\Components\TextInput::make('size')
                    ->required()
                    ->maxLength(20),
                Forms\Components\TextInput::make('weight_kg')
                    ->numeric(),
                Forms\Components\TextInput::make('declared_value')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\Toggle::make('has_insurance')
                    ->required(),
                Forms\Components\TextInput::make('insurance_amount')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('sender_code')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('sender_code_attempts')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\Toggle::make('sender_code_locked')
                    ->required(),
                Forms\Components\TextInput::make('recipient_code')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('recipient_code_attempts')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\Toggle::make('recipient_code_locked')
                    ->required(),
                Forms\Components\TextInput::make('recipient_name')
                    ->required()
                    ->maxLength(150),
                Forms\Components\TextInput::make('recipient_phone')
                    ->tel()
                    ->required()
                    ->maxLength(20),
                Forms\Components\Textarea::make('delivery_address')
                    ->required()
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('delivery_lat')
                    ->numeric(),
                Forms\Components\TextInput::make('delivery_lng')
                    ->numeric(),
                Forms\Components\Textarea::make('pickup_address')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('pickup_lat')
                    ->numeric(),
                Forms\Components\TextInput::make('pickup_lng')
                    ->numeric(),
                Forms\Components\Textarea::make('description')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('shipping_fee')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('platform_commission')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('courier_amount')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('payment_method')
                    ->required()
                    ->maxLength(30)
                    ->default('cash'),
                Forms\Components\TextInput::make('payment_status')
                    ->required()
                    ->maxLength(30)
                    ->default('pending'),
                Forms\Components\TextInput::make('payment_reference')
                    ->maxLength(100),
                Forms\Components\TextInput::make('assignment_attempts')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('sender_code_unlocked_by')
                    ->numeric(),
                Forms\Components\TextInput::make('recipient_code_unlocked_by')
                    ->numeric(),
                Forms\Components\DateTimePicker::make('picked_up_at'),
                Forms\Components\DateTimePicker::make('delivered_at'),
                Forms\Components\DateTimePicker::make('cancelled_at'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sender.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('courier.id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('city.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->searchable(),
                Tables\Columns\TextColumn::make('size')
                    ->searchable(),
                Tables\Columns\TextColumn::make('weight_kg')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('declared_value')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\IconColumn::make('has_insurance')
                    ->boolean(),
                Tables\Columns\TextColumn::make('insurance_amount')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sender_code')
                    ->searchable(),
                Tables\Columns\TextColumn::make('sender_code_attempts')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\IconColumn::make('sender_code_locked')
                    ->boolean(),
                Tables\Columns\TextColumn::make('recipient_code')
                    ->searchable(),
                Tables\Columns\TextColumn::make('recipient_code_attempts')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\IconColumn::make('recipient_code_locked')
                    ->boolean(),
                Tables\Columns\TextColumn::make('recipient_name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('recipient_phone')
                    ->searchable(),
                Tables\Columns\TextColumn::make('delivery_lat')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('delivery_lng')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('pickup_lat')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('pickup_lng')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('shipping_fee')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('platform_commission')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('courier_amount')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('payment_method')
                    ->searchable(),
                Tables\Columns\TextColumn::make('payment_status')
                    ->searchable(),
                Tables\Columns\TextColumn::make('payment_reference')
                    ->searchable(),
                Tables\Columns\TextColumn::make('assignment_attempts')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sender_code_unlocked_by')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('recipient_code_unlocked_by')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('picked_up_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('delivered_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('cancelled_at')
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
            'index' => Pages\ListParcels::route('/'),
            'create' => Pages\CreateParcel::route('/create'),
            'edit' => Pages\EditParcel::route('/{record}/edit'),
        ];
    }
}
