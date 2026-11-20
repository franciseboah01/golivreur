<?php

namespace App\Filament\Resources\CityResource\RelationManagers;

use App\Filament\Support\FilamentCityScope;
use App\Models\City;
use App\Models\Neighborhood;
use App\Services\IvoryCoastGeocodingService;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
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
                    ->placeholder('Ex: Commerce, Résidentiel, Château...')
                    ->required()
                    ->maxLength(120)
                    ->suffixAction(
                        Forms\Components\Actions\Action::make('locateNeighborhood')
                            ->label('Chercher')
                            ->icon('heroicon-m-magnifying-glass')
                            ->tooltip('Rechercher ce quartier dans la ville (OpenStreetMap). S\'il n\'est pas référencé, cliquez sur la vue Satellite ci-dessous ou laissez le GPS vide.')
                            ->action(function (?string $state, Get $get, Set $set): void {
                                $query = trim((string) $state);
                                if ($query === '') {
                                    Notification::make()
                                        ->title('Saisissez d\'abord le nom du quartier.')
                                        ->warning()
                                        ->send();

                                    return;
                                }

                                /** @var City $city */
                                $city = $this->getOwnerRecord();
                                $cityLat = $city->center_lat !== null ? (float) $city->center_lat : null;
                                $cityLng = $city->center_lng !== null ? (float) $city->center_lng : null;

                                $found = app(IvoryCoastGeocodingService::class)->geocodeNeighborhood(
                                    neighborhoodName: $query,
                                    cityName: (string) $city->name,
                                    cityCenterLat: $cityLat,
                                    cityCenterLng: $cityLng,
                                    maxRadiusKm: $city->radius_km,
                                );

                                if ($found === null) {
                                    Notification::make()
                                        ->title("Quartier « {$query} » non référencé sur OpenStreetMap")
                                        ->body('Pas de souci : cliquez directement sur l\'emplacement en vue Satellite ci-dessous, ou laissez le GPS vide avec une « Distance estimée (km) » — aucun client ne sera bloqué.')
                                        ->info()
                                        ->send();

                                    return;
                                }

                                $set('lat', $found['lat']);
                                $set('lng', $found['lng']);

                                $currentDist = $get('distance_km');
                                if (
                                    ($currentDist === null || $currentDist === '' || (float) $currentDist <= 0)
                                    && $cityLat !== null
                                    && $cityLng !== null
                                    && ! (abs($cityLat) < 0.0001 && abs($cityLng) < 0.0001)
                                ) {
                                    $km = City::distanceHaversine($cityLat, $cityLng, $found['lat'], $found['lng']);
                                    $set('distance_km', max(0.5, round($km, 1)));
                                }

                                Notification::make()
                                    ->title("Quartier localisé : {$query}")
                                    ->body("Point placé ({$found['lat']}, {$found['lng']}). Vous pouvez ajuster le marqueur sur la vue Satellite.")
                                    ->success()
                                    ->send();
                            }),
                    ),

                Forms\Components\Toggle::make('is_active')
                    ->label('Actif')
                    ->default(true),

                Forms\Components\TextInput::make('distance_km')
                    ->label('Distance estimée (km)')
                    ->placeholder('Ex: 2.5')
                    ->helperText('Utilisée pour calculer le tarif de livraison si le quartier n\'a pas de repère GPS.')
                    ->numeric()
                    ->minValue(0.1)
                    ->step(0.1)
                    ->nullable(),

                Forms\Components\View::make('filament.components.gps-map-picker')
                    ->viewData(function (Forms\Components\View $component): array {
                        /** @var City $city */
                        $city = $this->getOwnerRecord();
                        $hasCityCenter = $city->center_lat !== null
                            && $city->center_lng !== null
                            && ! (abs((float) $city->center_lat) < 0.0001 && abs((float) $city->center_lng) < 0.0001);

                        $prefix = $component->getContainer()->getStatePath();
                        $latPath = $prefix !== '' ? "{$prefix}.lat" : 'lat';
                        $lngPath = $prefix !== '' ? "{$prefix}.lng" : 'lng';
                        $distancePath = $prefix !== '' ? "{$prefix}.distance_km" : 'distance_km';

                        $cityRadiusKm = $city->radius_km;
                        $smartZoom = ! $hasCityCenter
                            ? 6
                            : ($cityRadiusKm <= 6.0 ? 15 : ($cityRadiusKm <= 15.0 ? 14 : 13));

                        return [
                            'mode' => 'neighborhood',
                            'latPath' => $latPath,
                            'lngPath' => $lngPath,
                            'distancePath' => $distancePath,
                            'defaultLat' => $hasCityCenter ? (float) $city->center_lat : 7.539989,
                            'defaultLng' => $hasCityCenter ? (float) $city->center_lng : -5.547080,
                            'defaultZoom' => $smartZoom,
                            'cityCenterLat' => $hasCityCenter ? (float) $city->center_lat : null,
                            'cityCenterLng' => $hasCityCenter ? (float) $city->center_lng : null,
                            'radiusMeters' => (int) round($cityRadiusKm * 1000),
                            'defaultLayer' => 'satellite',
                        ];
                    })
                    ->columnSpanFull(),

                Forms\Components\TextInput::make('lat')
                    ->label('Latitude GPS (facultatif)')
                    ->helperText('Rempli par clic sur la vue Satellite ou laissé vide.')
                    ->numeric()
                    ->step(0.000001)
                    ->nullable()
                    ->live(onBlur: true)
                    ->rule(function (Get $get): Closure {
                        return function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            /** @var City $city */
                            $city = $this->getOwnerRecord();
                            $lat = ($value === null || $value === '') ? null : (float) $value;
                            $rawLng = $get('lng');
                            $lng = ($rawLng === null || $rawLng === '') ? null : (float) $rawLng;
                            $error = app(IvoryCoastGeocodingService::class)->validateNeighborhoodWithinCity($city, $lat, $lng);
                            if ($error !== null) {
                                $fail($error);
                            }
                        };
                    }),

                Forms\Components\TextInput::make('lng')
                    ->label('Longitude GPS (facultatif)')
                    ->helperText('En Côte d\'Ivoire : toujours négative (avec le signe « - »), ou laissé vide.')
                    ->numeric()
                    ->step(0.000001)
                    ->nullable()
                    ->live(onBlur: true)
                    ->rule(function (Get $get): Closure {
                        return function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            /** @var City $city */
                            $city = $this->getOwnerRecord();
                            $rawLat = $get('lat');
                            $lat = ($rawLat === null || $rawLat === '') ? null : (float) $rawLat;
                            $lng = ($value === null || $value === '') ? null : (float) $value;
                            $error = app(IvoryCoastGeocodingService::class)->validateNeighborhoodWithinCity($city, $lat, $lng);
                            if ($error !== null) {
                                $fail($error);
                            }
                        };
                    }),
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

                Tables\Columns\TextColumn::make('gps_status')
                    ->label('Repère GPS')
                    ->badge()
                    ->state(fn (Neighborhood $record): string => ($record->lat !== null && $record->lng !== null) ? 'Positionné' : 'Sans GPS (OK)')
                    ->color(fn (Neighborhood $record): string => ($record->lat !== null && $record->lng !== null) ? 'success' : 'gray'),
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
