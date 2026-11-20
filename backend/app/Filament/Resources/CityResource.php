<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CityResource\Pages;
use App\Filament\Resources\CityResource\RelationManagers\NeighborhoodsRelationManager;
use App\Filament\Support\FilamentCityScope;
use App\Models\ActivityLog;
use App\Models\City;
use App\Services\IvoryCoastGeocodingService;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CityResource extends Resource
{
    protected static ?string $model = City::class;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Villes & Zones d\'Opération';

    protected static ?string $modelLabel = 'Ville';

    protected static ?string $pluralModelLabel = 'Villes & Zones d\'Opération';

    protected static ?int $navigationSort = 10;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->hasHabilitationTab('cities');
    }

    public static function getEloquentQuery(): Builder
    {
        return FilamentCityScope::scopeByCity(parent::getEloquentQuery(), 'id');
    }

    public static function canCreate(): bool
    {
        return ! FilamentCityScope::isLocal() && parent::canCreate();
    }

    public static function canEdit(Model $record): bool
    {
        if (FilamentCityScope::isLocal()) {
            return FilamentCityScope::canAccessCityId((int) $record->getKey());
        }

        return parent::canEdit($record);
    }

    public static function canDelete(Model $record): bool
    {
        return ! FilamentCityScope::isLocal() && parent::canDelete($record);
    }

    public static function canDeleteAny(): bool
    {
        return ! FilamentCityScope::isLocal() && parent::canDeleteAny();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Configuration Géographique')
                    ->description('Saisissez le nom de la ville puis cliquez sur la loupe pour localiser automatiquement son vrai centre-ville (sans jamais utiliser la position d\'un bureau excentré).')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nom officiel de la ville')
                            ->placeholder('Ex: Sirasso, Daloa, Korhogo, Abidjan...')
                            ->required()
                            ->maxLength(100)
                            ->suffixAction(
                                Forms\Components\Actions\Action::make('locateCity')
                                    ->label('Localiser')
                                    ->icon('heroicon-m-magnifying-glass')
                                    ->tooltip('Trouver automatiquement le centre officiel de la ville en Côte d\'Ivoire (OpenStreetMap)')
                                    ->action(function (?string $state, Set $set): void {
                                        $query = trim((string) $state);
                                        if ($query === '') {
                                            Notification::make()
                                                ->title('Saisissez d\'abord le nom de la ville.')
                                                ->warning()
                                                ->send();

                                            return;
                                        }

                                        $found = app(IvoryCoastGeocodingService::class)->geocodeCity($query);
                                        if ($found === null) {
                                            Notification::make()
                                                ->title("Ville « {$query} » introuvable automatiquement")
                                                ->body('Cliquez directement sur le centre de la ville sur la carte (mode Plan ou Satellite), ou laissez les coordonnées vides (aucun utilisateur ne sera bloqué).')
                                                ->warning()
                                                ->send();

                                            return;
                                        }

                                        $set('center_lat', $found['lat']);
                                        $set('center_lng', $found['lng']);

                                        Notification::make()
                                            ->title("Centre-ville trouvé : {$query}")
                                            ->body("{$found['display_name']} ({$found['lat']}, {$found['lng']}). Vérifiez sur la carte que le cercle orange englobe toute la ville.")
                                            ->success()
                                            ->send();
                                    }),
                            ),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Ville ouverte au service GoLivreur')
                            ->default(true),

                        Forms\Components\TextInput::make('radius_km')
                            ->label('Rayon de couverture de la ville (km)')
                            ->helperText('Ajustez selon la taille réelle : ex. 3 à 5 km (petite commune / Sirasso), 10 à 15 km (Daloa, Korhogo), 30 à 45 km (Grand Abidjan). Le cercle orange sur la carte s\'adapte en direct.')
                            ->numeric()
                            ->minValue(City::RAYON_MIN_VILLE_KM)
                            ->maxValue(City::RAYON_MAX_CONFIG_VILLE_KM)
                            ->step(0.5)
                            ->default(10.0)
                            ->suffix('km')
                            ->required()
                            ->live()
                            ->columnSpanFull(),

                        Forms\Components\View::make('filament.components.gps-map-picker')
                            ->viewData(function (Forms\Components\View $component): array {
                                $prefix = $component->getContainer()->getStatePath();
                                $latPath = $prefix !== '' ? "{$prefix}.center_lat" : 'center_lat';
                                $lngPath = $prefix !== '' ? "{$prefix}.center_lng" : 'center_lng';
                                $radiusPath = $prefix !== '' ? "{$prefix}.radius_km" : 'radius_km';

                                return [
                                    'mode' => 'city',
                                    'latPath' => $latPath,
                                    'lngPath' => $lngPath,
                                    'radiusPath' => $radiusPath,
                                    'defaultLat' => 7.539989,
                                    'defaultLng' => -5.547080,
                                    'defaultZoom' => 6,
                                    'radiusMeters' => 10000,
                                    'defaultLayer' => 'plan',
                                ];
                            })
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('center_lat')
                            ->label('Latitude GPS du centre (rempli automatiquement)')
                            ->placeholder('Ex: 9.4580 (Sirasso) ou 6.8770 (Daloa)')
                            ->helperText('Facultatif — Côte d\'Ivoire : entre 4.2 et 10.85. Laisser vide ne bloque aucun client.')
                            ->numeric()
                            ->step(0.000001)
                            ->nullable()
                            ->live(onBlur: true)
                            ->rule(function (Get $get): Closure {
                                return function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                    $lat = ($value === null || $value === '') ? null : (float) $value;
                                    $rawLng = $get('center_lng');
                                    $lng = ($rawLng === null || $rawLng === '') ? null : (float) $rawLng;
                                    $error = app(IvoryCoastGeocodingService::class)->validateCoordinates($lat, $lng);
                                    if ($error !== null) {
                                        $fail($error);
                                    }
                                };
                            }),
                        Forms\Components\TextInput::make('center_lng')
                            ->label('Longitude GPS du centre (rempli automatiquement)')
                            ->placeholder('Ex: -6.2080 (Sirasso) ou -6.4502 (Daloa)')
                            ->helperText('Facultatif — Côte d\'Ivoire : TOUJOURS NÉGATIVE (entre -8.7 et -2.4, avec le signe « - »).')
                            ->numeric()
                            ->step(0.000001)
                            ->nullable()
                            ->live(onBlur: true)
                            ->rule(function (Get $get): Closure {
                                return function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                    $rawLat = $get('center_lat');
                                    $lat = ($rawLat === null || $rawLat === '') ? null : (float) $rawLat;
                                    $lng = ($value === null || $value === '') ? null : (float) $value;
                                    $error = app(IvoryCoastGeocodingService::class)->validateCoordinates($lat, $lng);
                                    if ($error !== null) {
                                        $fail($error);
                                    }
                                };
                            }),
                        Forms\Components\TextInput::make('support_phone')
                            ->label('Numéro de service / Support client de la ville')
                            ->placeholder('Ex: +225 07 01 02 03 04 ou 0701020304')
                            ->helperText('Visible et appelable directement depuis GL Client, GL Business et GL Driver pour cette ville.')
                            ->tel()
                            ->maxLength(30)
                            ->columnSpanFull(),
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
                        Forms\Components\Textarea::make('emergency_mode_message')
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

                Tables\Columns\TextColumn::make('neighborhoods_count')
                    ->counts('neighborhoods')
                    ->label('Quartiers')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('center_gps')
                    ->label('Centre GPS (Lat, Lng)')
                    ->state(fn (City $record): string => $record->center_lat !== null && $record->center_lng !== null
                        ? number_format((float) $record->center_lat, 4, '.', '').', '.number_format((float) $record->center_lng, 4, '.', '')
                        : 'Non défini (OK)')
                    ->color(fn (City $record): string => ($record->center_lng !== null && (float) $record->center_lng > 0) ? 'danger' : 'gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('radius_km_display')
                    ->label('Rayon')
                    ->badge()
                    ->state(fn (City $record): string => rtrim(rtrim(number_format($record->radius_km, 1, '.', ''), '0'), '.').' km')
                    ->color('warning'),

                Tables\Columns\TextColumn::make('support_phone')
                    ->label('N° Service Client')
                    ->icon('heroicon-m-phone')
                    ->color('success')
                    ->copyable()
                    ->copyMessage('Numéro de service copié'),

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

                Tables\Columns\TextColumn::make('emergency_mode_message')
                    ->label('Message d\'Alerte')
                    ->placeholder('Aucun')
                    ->limit(40),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date d\'Ajout')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'asc')
            ->defaultKeySort(true)
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Statut Opérationnel')
                    ->trueLabel('Villes Ouvertes / Actives')
                    ->falseLabel('Villes Suspendues / Inactives'),

                Tables\Filters\TernaryFilter::make('rain_mode_active')
                    ->label('Mode Pluie (+20%)')
                    ->trueLabel('Mode Pluie Enclenché')
                    ->falseLabel('Météo Normale'),

                Tables\Filters\TernaryFilter::make('emergency_mode_active')
                    ->label('Mode Urgence')
                    ->trueLabel('Mode Urgence Enclenché')
                    ->falseLabel('Opérations Normales'),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('created_from')->label('Ajoutée à partir du'),
                        Forms\Components\DatePicker::make('created_until')->label('Ajoutée jusqu\'au'),
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
                Tables\Actions\Action::make('toggleRain')
                    ->label(fn (City $record): string => $record->rain_mode_active ? 'Couper Pluie' : 'Activer Pluie')
                    ->icon(fn (City $record): string => $record->rain_mode_active ? 'heroicon-m-sun' : 'heroicon-m-cloud')
                    ->color(fn (City $record): string => $record->rain_mode_active ? 'gray' : 'warning')
                    ->action(function (City $record): void {
                        FilamentCityScope::authorizeCityId((int) $record->id);

                        $newStatus = ! $record->rain_mode_active;
                        $record->update([
                            'rain_mode_active' => $newStatus,
                            'rain_mode_activated_at' => $newStatus ? now() : null,
                        ]);

                        ActivityLog::create([
                            'user_id' => auth()->id(),
                            'action' => $newStatus ? 'rain_mode_activated' : 'rain_mode_deactivated',
                            'entity_type' => 'city',
                            'entity_id' => $record->id,
                            'properties' => ['city_name' => $record->name, 'new_status' => $newStatus],
                            'ip_address' => request()->ip(),
                            'severity' => 'info',
                        ]);

                        Notification::make()
                            ->title("Mode Pluie {$record->name} : ".($newStatus ? 'Activé (+20%)' : 'Désactivé'))
                            ->color($newStatus ? 'warning' : 'success')
                            ->send();
                    }),

                Tables\Actions\Action::make('toggleEmergency')
                    ->label(fn (City $record): string => $record->emergency_mode_active ? 'Lever Urgence' : 'Déclencher Urgence')
                    ->icon(fn (City $record): string => $record->emergency_mode_active ? 'heroicon-m-shield-check' : 'heroicon-m-exclamation-triangle')
                    ->color(fn (City $record): string => $record->emergency_mode_active ? 'success' : 'danger')
                    ->visible(fn (): bool => in_array(auth()->user()?->role, ['super_admin', 'dg_national'], true))
                    ->form(fn (City $record): array => $record->emergency_mode_active ? [] : [
                        Forms\Components\Textarea::make('message')
                            ->label('Message d\'alerte diffusé aux clients et livreurs de cette ville')
                            ->default('En raison d\'intempéries ou de circonstances exceptionnelles, les livraisons sont momentanément suspendues.')
                            ->required(),
                    ])
                    ->modalHeading(fn (City $record): string => $record->emergency_mode_active ? "Lever l'urgence sur {$record->name}" : "Déclencher l'urgence sur {$record->name}")
                    ->action(function (City $record, array $data): void {
                        if (! in_array(auth()->user()?->role, ['super_admin', 'dg_national'], true)) {
                            abort(403, 'Action réservée à la direction nationale.');
                        }

                        $newStatus = ! $record->emergency_mode_active;
                        $record->update([
                            'emergency_mode_active' => $newStatus,
                            'emergency_mode_activated_at' => $newStatus ? now() : null,
                            'emergency_mode_message' => $newStatus ? ($data['message'] ?? 'Service temporairement interrompu') : null,
                        ]);

                        ActivityLog::create([
                            'user_id' => auth()->id(),
                            'action' => $newStatus ? 'emergency_mode_activated' : 'emergency_mode_deactivated',
                            'entity_type' => 'city',
                            'entity_id' => $record->id,
                            'properties' => [
                                'city_name' => $record->name,
                                'new_status' => $newStatus,
                                'message' => $data['message'] ?? null,
                            ],
                            'ip_address' => request()->ip(),
                            'severity' => 'critical',
                        ]);

                        Notification::make()
                            ->title("Mode Urgence {$record->name} : ".($newStatus ? 'Enclenché' : 'Levé'))
                            ->color($newStatus ? 'danger' : 'success')
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
        return [
            NeighborhoodsRelationManager::class,
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
