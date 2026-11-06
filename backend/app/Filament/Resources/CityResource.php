<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CityResource\Pages;
use App\Models\City;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CityResource extends Resource
{
    protected static ?string $model = City::class;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Villes & Zones d\'Opération';

    protected static ?string $modelLabel = 'Ville';

    protected static ?string $pluralModelLabel = 'Villes & Zones d\'Opération';

    protected static ?int $navigationSort = 7;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Configuration Géographique')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nom officiel de la ville')
                            ->required()
                            ->maxLength(100),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Ville ouverte au service GoLivreur')
                            ->default(true),
                        Forms\Components\TextInput::make('center_lat')
                            ->label('Latitude du centre')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('center_lng')
                            ->label('Longitude du centre')
                            ->numeric()
                            ->required(),
                    ])->columns(2),

                Forms\Components\Section::make('Majorations & Sécurité Urbaine')
                    ->description('Gestion en temps réel de la météo et des crises locales')
                    ->schema([
                        Forms\Components\Toggle::make('rain_mode_active')
                            ->label('Activer le Mode Pluie (+20% sur la livraison)'),
                        Forms\Components\DateTimePicker::make('rain_mode_activated_at')
                            ->label('Date/Heure déclenchement Mode Pluie'),
                        Forms\Components\Toggle::make('emergency_mode_active')
                            ->label('Activer le Mode Urgence (Interrompt les nouvelles commandes)'),
                        Forms\Components\Textarea::make('emergency_message')
                            ->label('Message diffusé aux clients et livreurs en cas d\'urgence')
                            ->placeholder('Ex: En raison des fortes inondations, les livraisons sont suspendues jusqu\'à nouvel ordre.')
                            ->columnSpanFull(),
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
                    ->label('Ville')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Opérationnelle')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\IconColumn::make('rain_mode_active')
                    ->label('Mode Pluie (+20%)')
                    ->boolean()
                    ->trueIcon('heroicon-o-cloud')
                    ->falseIcon('heroicon-o-sun')
                    ->trueColor('warning')
                    ->falseColor('gray'),

                Tables\Columns\IconColumn::make('emergency_mode_active')
                    ->label('Mode Urgence')
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->falseIcon('heroicon-o-shield-check')
                    ->trueColor('danger')
                    ->falseColor('success'),

                Tables\Columns\TextColumn::make('emergency_message')
                    ->label('Message d\'Alerte')
                    ->placeholder('Aucun')
                    ->limit(40),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date Ajout')
                    ->dateTime('d/m/Y')
                    ->sortable(),
            ])
            ->defaultSort('name', 'asc')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Statut Opérationnel'),
                Tables\Filters\TernaryFilter::make('rain_mode_active')
                    ->label('Mode Pluie'),
                Tables\Filters\TernaryFilter::make('emergency_mode_active')
                    ->label('Mode Urgence'),
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
            'index' => Pages\ListCities::route('/'),
            'create' => Pages\CreateCity::route('/create'),
            'edit' => Pages\EditCity::route('/{record}/edit'),
        ];
    }
}
