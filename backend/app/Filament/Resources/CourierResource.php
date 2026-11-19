<?php

namespace App\Filament\Resources;

use App\Filament\Components\ImageUploadField;
use App\Filament\Components\SearchableSelect;
use App\Filament\Resources\CourierResource\Pages;
use App\Filament\Support\FilamentCityScope;
use App\Models\ActivityLog;
use App\Models\Courier;
use App\Models\User;
use App\Models\VehicleType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CourierResource extends Resource
{
    protected static ?string $model = Courier::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Partenaires';

    protected static ?string $navigationLabel = 'Livreurs Partenaires';

    protected static ?string $modelLabel = 'Livreur Partenaire';

    protected static ?string $pluralModelLabel = 'Livreurs Partenaires';

    protected static ?int $navigationSort = 5;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->hasHabilitationTab('couriers');
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
                Forms\Components\Section::make('Identité & Profil du Livreur')
                    ->description('Renseignez le compte utilisateur, la ville d\'affectation, le véhicule et la photo du livreur.')
                    ->schema([
                        // Listes déroulantes remontées en haut du formulaire avec recherche live et tri alphabétique
                        SearchableSelect::make('user_id')
                            ->label('Compte Utilisateur (Livreur)')
                            ->relationship('user', 'name', modifyQueryUsing: fn (Builder $query) => FilamentCityScope::scopeByCity($query)
                                ->where(fn (Builder $q) => $q->whereIn('role', ['driver', 'courier'])->orWhereDoesntHave('courier'))
                                ->orderBy('name'))
                            ->getOptionLabelFromRecordUsing(fn (User $record): string => "{$record->name} (".($record->phone ?? 'Sans téléphone').')')
                            ->required()
                            ->helperText('Sélectionnez un livreur existant ou créez-en un nouveau via le bouton +.')
                            ->createOptionForm([
                                Forms\Components\TextInput::make('name')
                                    ->label('Nom et Prénom du Livreur')
                                    ->required()
                                    ->maxLength(100),
                                Forms\Components\TextInput::make('phone')
                                    ->label('Numéro de Téléphone Professionnel')
                                    ->tel()
                                    ->required()
                                    ->unique('users', 'phone')
                                    ->maxLength(20),
                                Forms\Components\TextInput::make('email')
                                    ->label('Email de Connexion')
                                    ->email()
                                    ->required()
                                    ->unique('users', 'email')
                                    ->maxLength(100),
                                Forms\Components\TextInput::make('password')
                                    ->label('Mot de passe')
                                    ->password()
                                    ->required()
                                    ->minLength(8),
                            ])
                            ->createOptionUsing(function (array $data): int {
                                if (! FilamentCityScope::hasValidLocalCity()) {
                                    abort(403, 'Aucune ville rattachée à votre compte administrateur.');
                                }

                                $rawPhone = preg_replace('/[\s\.\-\(\)]+/', '', trim((string) $data['phone'])) ?? '';
                                if (str_starts_with($rawPhone, '+225')) {
                                    $normalizedPhone = substr($rawPhone, 4);
                                } elseif (str_starts_with($rawPhone, '00225')) {
                                    $normalizedPhone = substr($rawPhone, 5);
                                } elseif (strlen($rawPhone) === 13 && str_starts_with($rawPhone, '225')) {
                                    $normalizedPhone = substr($rawPhone, 3);
                                } else {
                                    $normalizedPhone = $rawPhone;
                                }

                                $user = User::create([
                                    'name' => $data['name'],
                                    'phone' => $normalizedPhone,
                                    'email' => $data['email'],
                                    'password' => Hash::make((string) $data['password']),
                                    'role' => 'driver',
                                    'city_id' => FilamentCityScope::localCityId(),
                                    'is_active' => true,
                                    'phone_verified_at' => now(),
                                ]);

                                return $user->id;
                            }),

                        FilamentCityScope::configureCitySelect(
                            SearchableSelect::make('city_id')->label('Ville d\'affectation')
                        )
                            ->required(),

                        SearchableSelect::make('vehicle_type')
                            ->label('Type de véhicule')
                            ->options(fn () => VehicleType::where('is_active', true)->orderBy('name')->pluck('name', 'slug')->toArray())
                            ->required()
                            ->createOptionForm([
                                Forms\Components\TextInput::make('name')
                                    ->label('Nom du véhicule')
                                    ->required()
                                    ->maxLength(100),
                                Forms\Components\TextInput::make('slug')
                                    ->label('Identifiant unique (slug)')
                                    ->required()
                                    ->maxLength(50),
                                Forms\Components\Textarea::make('description')
                                    ->label('Description'),
                            ])
                            ->createOptionUsing(function (array $data): string {
                                $slug = Str::slug($data['slug'] ?? $data['name']);
                                $vt = VehicleType::create([
                                    'name' => $data['name'],
                                    'slug' => $slug,
                                    'description' => $data['description'] ?? null,
                                    'is_active' => true,
                                ]);

                                return $vt->slug;
                            }),

                        ImageUploadField::make(
                            'photo_path',
                            'livreurs',
                            'Photo du livreur (Portrait)'
                        )->columnSpanFull(),

                        Forms\Components\TextInput::make('vehicle_plate')
                            ->label('Numéro d\'immatriculation du véhicule')
                            ->placeholder('Ex: 1234 AB 01')
                            ->maxLength(30),

                        Forms\Components\TextInput::make('id_card_number')
                            ->label('N° Pièce d\'identité (CNI / Passeport)')
                            ->maxLength(50),

                        Forms\Components\TextInput::make('driver_license_number')
                            ->label('N° Permis de conduire')
                            ->placeholder('Ex: CI-DL-987654')
                            ->maxLength(50),
                    ])->columns(2),

                Forms\Components\Section::make('Statut & Disponibilité')
                    ->schema([
                        Forms\Components\Toggle::make('is_online')
                            ->label('Actuellement en ligne et disponible'),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Profil vérifié et validé par l\'administration'),
                        Forms\Components\TextInput::make('consecutive_refusals')
                            ->label('Refus consécutifs enregistrés')
                            ->numeric()
                            ->default(0),
                        Forms\Components\DateTimePicker::make('penalty_until')
                            ->label('Pénalité / Suspension temporaire jusqu\'au'),
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

                Tables\Columns\ImageColumn::make('photo_path')
                    ->label('Photo')
                    ->disk('public')
                    ->circular()
                    ->defaultImageUrl(asset('images/placeholder-driver.png')),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Livreur')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.phone')
                    ->label('Téléphone')
                    ->searchable(),

                Tables\Columns\TextColumn::make('city.name')
                    ->label('Ville')
                    ->sortable(),

                Tables\Columns\TextColumn::make('vehicle_type')
                    ->label('Véhicule')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => VehicleType::where('slug', $state)->value('name') ?? ucfirst($state)),

                Tables\Columns\TextColumn::make('vehicle_plate')
                    ->label('Plaque')
                    ->searchable()
                    ->placeholder('-'),

                Tables\Columns\IconColumn::make('is_online')
                    ->label('En Ligne')
                    ->boolean()
                    ->trueIcon('heroicon-o-signal')
                    ->falseIcon('heroicon-o-no-symbol')
                    ->trueColor('success')
                    ->falseColor('gray'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Validé')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-badge')
                    ->falseIcon('heroicon-o-clock')
                    ->trueColor('success')
                    ->falseColor('warning'),

                Tables\Columns\TextColumn::make('average_rating')
                    ->label('Note')
                    ->formatStateUsing(fn ($state) => '★ '.number_format((float) $state, 1))
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_ratings')
                    ->label('Avis')
                    ->sortable(),

                Tables\Columns\TextColumn::make('consecutive_refusals')
                    ->label('Refus')
                    ->badge()
                    ->color(fn ($state) => (int) $state >= 3 ? 'danger' : 'gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date Inscription')
                    ->dateTime('d/m/Y')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'asc')
            ->defaultKeySort(true)
            ->filters([
                Tables\Filters\TernaryFilter::make('is_online')
                    ->label('Disponibilité')
                    ->trueLabel('Livreurs En Ligne')
                    ->falseLabel('Livreurs Hors-Ligne'),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Statut Compte')
                    ->trueLabel('Comptes Validés')
                    ->falseLabel('Comptes En Attente'),
                Tables\Filters\SelectFilter::make('vehicle_type')
                    ->label('Type de Véhicule')
                    ->options(fn () => VehicleType::pluck('name', 'slug')->toArray()),
                Tables\Filters\SelectFilter::make('city_id')
                    ->label('Filtrer par Ville')
                    ->relationship('city', 'name', modifyQueryUsing: fn (Builder $query) => FilamentCityScope::scopeByCity($query, 'cities.id'))
                    ->visible(fn (): bool => ! FilamentCityScope::isLocal()),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('created_from')->label('Inscrit à partir du'),
                        Forms\Components\DatePicker::make('created_until')->label('Inscrit jusqu\'au'),
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
                Tables\Actions\ViewAction::make()->label('Détail'),

                Tables\Actions\Action::make('toggleActive')
                    ->label(fn (Courier $record): string => $record->is_active ? 'Suspendre' : 'Valider & Activer')
                    ->icon(fn (Courier $record): string => $record->is_active ? 'heroicon-m-no-symbol' : 'heroicon-m-check-circle')
                    ->color(fn (Courier $record): string => $record->is_active ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Courier $record): string => $record->is_active ? "Suspendre le livreur {$record->user?->name}" : "Valider et activer {$record->user?->name}")
                    ->action(function (Courier $record): void {
                        FilamentCityScope::authorizeRecord($record);

                        $newStatus = ! $record->is_active;
                        $record->update([
                            'is_active' => $newStatus,
                            'activated_at' => $newStatus ? now() : null,
                            'activated_by' => $newStatus ? auth()->id() : null,
                        ]);

                        if ($record->user) {
                            $record->user->update(['is_active' => $newStatus]);
                        }

                        ActivityLog::create([
                            'user_id' => auth()->id(),
                            'action' => $newStatus ? 'courier_activated' : 'courier_suspended',
                            'entity_type' => 'courier',
                            'entity_id' => $record->id,
                            'properties' => ['courier_id' => $record->id, 'new_status' => $newStatus],
                            'ip_address' => request()->ip(),
                            'severity' => 'warning',
                        ]);

                        Notification::make()
                            ->title("Livreur {$record->user?->name} ".($newStatus ? 'validé et activé' : 'suspendu'))
                            ->color($newStatus ? 'success' : 'danger')
                            ->send();
                    }),

                Tables\Actions\Action::make('applyPenalty')
                    ->label('Pénalité')
                    ->icon('heroicon-m-clock')
                    ->color('warning')
                    ->form([
                        Forms\Components\TextInput::make('minutes')
                            ->label('Durée de suspension (minutes)')
                            ->numeric()
                            ->default(15)
                            ->required(),
                        Forms\Components\Textarea::make('reason')
                            ->label('Motif de la sanction')
                            ->required(),
                    ])
                    ->action(function (Courier $record, array $data): void {
                        FilamentCityScope::authorizeRecord($record);

                        $penaltyUntil = now()->addMinutes((int) $data['minutes']);
                        $record->update([
                            'penalty_until' => $penaltyUntil,
                            'is_online' => false,
                        ]);

                        ActivityLog::create([
                            'user_id' => auth()->id(),
                            'action' => 'courier_penalty_applied',
                            'entity_type' => 'courier',
                            'entity_id' => $record->id,
                            'properties' => [
                                'minutes' => $data['minutes'],
                                'penalty_until' => $penaltyUntil->toDateTimeString(),
                                'reason' => $data['reason'],
                            ],
                            'ip_address' => request()->ip(),
                            'severity' => 'warning',
                        ]);

                        Notification::make()
                            ->title("Pénalité de {$data['minutes']} min appliquée à {$record->user?->name}")
                            ->warning()
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
        return [];
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
