<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Filament\Resources\OrderResource\RelationManagers;
use App\Models\Order;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationGroup = 'Opérations GoShop';

    protected static ?string $modelLabel = 'Commande GoShop';

    protected static ?string $pluralModelLabel = 'Commandes GoShop';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('client_id')
                    ->relationship('client', 'name')
                    ->required(),
                Forms\Components\Select::make('merchant_id')
                    ->relationship('merchant', 'name')
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
                Forms\Components\TextInput::make('products_total')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('delivery_fee')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('platform_commission')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('merchant_amount')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('courier_amount')
                    ->required()
                    ->numeric(),
                Forms\Components\Toggle::make('rain_mode_applied')
                    ->required(),
                Forms\Components\Toggle::make('peak_hours_applied')
                    ->required(),
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
                Forms\Components\TextInput::make('confirmation_code')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('confirmation_attempts')
                    ->required()
                    ->numeric()
                    ->default(0),
                Forms\Components\Toggle::make('confirmation_locked')
                    ->required(),
                Forms\Components\TextInput::make('confirmation_unlocked_by')
                    ->numeric(),
                Forms\Components\Textarea::make('delivery_address')
                    ->required()
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('delivery_lat')
                    ->numeric(),
                Forms\Components\TextInput::make('delivery_lng')
                    ->numeric(),
                Forms\Components\TextInput::make('pickup_lat')
                    ->numeric(),
                Forms\Components\TextInput::make('pickup_lng')
                    ->numeric(),
                Forms\Components\Textarea::make('special_instructions')
                    ->columnSpanFull(),
                Forms\Components\DateTimePicker::make('accepted_at'),
                Forms\Components\DateTimePicker::make('ready_at'),
                Forms\Components\DateTimePicker::make('picked_up_at'),
                Forms\Components\DateTimePicker::make('delivered_at'),
                Forms\Components\DateTimePicker::make('cancelled_at'),
                Forms\Components\TextInput::make('cancel_reason')
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('client.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('merchant.name')
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
                Tables\Columns\TextColumn::make('products_total')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('delivery_fee')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('platform_commission')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('merchant_amount')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('courier_amount')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\IconColumn::make('rain_mode_applied')
                    ->boolean(),
                Tables\Columns\IconColumn::make('peak_hours_applied')
                    ->boolean(),
                Tables\Columns\TextColumn::make('payment_method')
                    ->searchable(),
                Tables\Columns\TextColumn::make('payment_status')
                    ->searchable(),
                Tables\Columns\TextColumn::make('payment_reference')
                    ->searchable(),
                Tables\Columns\TextColumn::make('confirmation_code')
                    ->searchable(),
                Tables\Columns\TextColumn::make('confirmation_attempts')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\IconColumn::make('confirmation_locked')
                    ->boolean(),
                Tables\Columns\TextColumn::make('confirmation_unlocked_by')
                    ->numeric()
                    ->sortable(),
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
                Tables\Columns\TextColumn::make('accepted_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('ready_at')
                    ->dateTime()
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
                Tables\Columns\TextColumn::make('cancel_reason')
                    ->searchable(),
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
            'index' => Pages\ListOrders::route('/'),
            'create' => Pages\CreateOrder::route('/create'),
            'edit' => Pages\EditOrder::route('/{record}/edit'),
        ];
    }
}
