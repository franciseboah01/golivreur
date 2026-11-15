<?php

namespace App\Filament\Resources;

use App\Filament\Components\SearchableSelect;
use App\Filament\Resources\AdministratorResource\Pages;
use App\Filament\Support\FilamentCityScope;
use App\Models\RoleHabilitation;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class AdministratorResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Comptes Administrateurs';

    protected static ?string $modelLabel = 'Administrateur';

    protected static ?string $pluralModelLabel = 'Comptes Administrateurs';

    protected static ?int $navigationSort = 8;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->hasHabilitationTab('administrators');
    }

    public static function canCreate(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        if ($user->role === 'city_manager') {
            return $user->city_id !== null;
        }

        return in_array($user->role, ['super_admin', 'dg_national'], true);
    }

    public static function canEdit(Model $record): bool
    {
        /** @var User|null $user */
        $user = auth()->user();
        if (! $user || ! ($record instanceof User)) {
            return false;
        }

        if ($user->role === 'super_admin') {
            return true;
        }

        if ($user->role === 'dg_national') {
            return $record->role !== 'super_admin';
        }

        if ($user->role === 'city_manager') {
            return $user->city_id !== null
                && $record->city_id !== null
                && (int) $record->city_id === (int) $user->city_id
                && in_array($record->role, ['city_support', 'city_agent', 'city_finance'], true);
        }

        return false;
    }

    public static function canDelete(Model $record): bool
    {
        /** @var User|null $user */
        $user = auth()->user();
        if (! $user || ! ($record instanceof User) || $user->id === $record->id) {
            return false;
        }

        if ($user->role === 'super_admin') {
            return true;
        }

        if ($user->role === 'dg_national') {
            return ! in_array($record->role, ['super_admin', 'dg_national'], true);
        }

        if ($user->role === 'city_manager') {
            return $user->city_id !== null
                && $record->city_id !== null
                && (int) $record->city_id === (int) $user->city_id
                && in_array($record->role, ['city_support', 'city_agent', 'city_finance'], true);
        }

        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->whereIn('role', User::ADMIN_ROLES);

        return FilamentCityScope::scopeByCity($query);
    }

    public static function getAllowedRolesForUser(?User $user): array
    {
        if (! $user) {
            return [];
        }

        if ($user->role === 'super_admin') {
            return [
                'super_admin' => 'Super Admin',
                'dg_national' => 'Directeur Général',
                'national_finance' => 'Finance Nationale',
                'national_tech' => 'Tech & IT',
                'national_support' => 'Support National',
                'city_manager' => 'City Manager',
                'city_finance' => 'Finance Ville',
                'city_support' => 'Support Ville',
                'city_agent' => 'Agent Ville',
            ];
        }

        if ($user->role === 'dg_national') {
            return [
                'national_finance' => 'Finance Nationale',
                'national_tech' => 'Tech & IT',
                'national_support' => 'Support National',
                'city_manager' => 'City Manager',
                'city_finance' => 'Finance Ville',
                'city_support' => 'Support Ville',
                'city_agent' => 'Agent Ville',
            ];
        }

        if ($user->role === 'city_manager') {
            return [
                'city_support' => 'Support Ville',
                'city_agent' => 'Agent Ville',
                'city_finance' => 'Finance Ville',
            ];
        }

        return [];
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Identité & Accès d\'Administration')
                    ->description('Gestion des comptes ayant accès au tableau de bord et à la gestion opérationnelle')
                    ->schema([
                        SearchableSelect::make('role')
                            ->label('Rôle Administratif')
                            ->options(fn () => static::getAllowedRolesForUser(auth()->user()))
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                if ($state) {
                                    $level = RoleHabilitation::where('slug', $state)->first();
                                    if ($level) {
                                        $set('role_habilitation_id', $level->id);
                                    }
                                }
                            }),
                        SearchableSelect::make('role_habilitation_id')
                            ->label('Niveau d\'Habilitation')
                            ->relationship('roleHabilitation', 'name', modifyQueryUsing: function (Builder $query) {
                                $allowedRoles = array_keys(static::getAllowedRolesForUser(auth()->user()));

                                return $query->whereIn('slug', $allowedRoles)->orderBy('name');
                            })
                            ->nullable()
                            ->live()
                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                if ($state) {
                                    $level = RoleHabilitation::find($state);
                                    if ($level && $level->slug) {
                                        $set('role', $level->slug);
                                    }
                                }
                            })
                            ->helperText('Tous les onglets et accès du panneau sont automatiquement hérités selon ce niveau.'),
                        SearchableSelect::make('city_id')
                            ->label('Ville d\'affectation')
                            ->relationship('city', 'name', modifyQueryUsing: fn (Builder $query) => $query->orderBy('name'))
                            ->default(function () {
                                /** @var User|null $user */
                                $user = auth()->user();

                                return ($user instanceof User && $user->role === 'city_manager') ? $user->city_id : null;
                            })
                            ->disabled(function () {
                                /** @var User|null $user */
                                $user = auth()->user();

                                return $user instanceof User && $user->role === 'city_manager';
                            })
                            ->dehydrated()
                            ->helperText(function () {
                                /** @var User|null $user */
                                $user = auth()->user();

                                return ($user instanceof User && $user->role === 'city_manager')
                                    ? 'Imposé automatiquement à votre ville.'
                                    : 'Laisser vide pour les fonctions à compétence nationale';
                            }),
                        Forms\Components\TextInput::make('name')
                            ->label('Nom et Prénom')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('email')
                            ->label('Email de connexion au panneau')
                            ->email()
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('phone')
                            ->label('Numéro de Téléphone Professionnel')
                            ->tel()
                            ->required()
                            ->maxLength(20),
                        Forms\Components\TextInput::make('password')
                            ->label('Mot de passe')
                            ->password()
                            ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                            ->dehydrated(fn ($state) => filled($state))
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->maxLength(255),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Compte administrateur autorisé et actif')
                            ->default(true),
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
                    ->label('Administrateur')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email de Connexion')
                    ->searchable(),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Téléphone')
                    ->searchable(),

                Tables\Columns\TextColumn::make('role')
                    ->label('Rôle Administratif')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'super_admin', 'dg_national' => 'danger',
                        'national_finance', 'city_finance' => 'warning',
                        'national_tech' => 'info',
                        'city_manager' => 'primary',
                        'city_support', 'national_support' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'super_admin' => 'Super Admin',
                        'dg_national' => 'Directeur Général',
                        'national_finance' => 'Finance Nationale',
                        'national_tech' => 'Tech & IT',
                        'national_support' => 'Support National',
                        'city_manager' => 'City Manager',
                        'city_finance' => 'Finance Ville',
                        'city_support' => 'Support Ville',
                        'city_agent' => 'Agent Ville',
                        'admin' => 'Administrateur',
                        default => ucfirst(str_replace('_', ' ', $state)),
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('roleHabilitation.name')
                    ->label('Niveau d\'Habilitation')
                    ->badge()
                    ->color('warning')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('city.name')
                    ->label('Périmètre / Ville')
                    ->placeholder('National')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Actif')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'asc')
            ->defaultKeySort(true)
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->label('Filtrer par Rôle')
                    ->options([
                        'super_admin' => 'Super Admin',
                        'dg_national' => 'Directeur Général',
                        'city_manager' => 'City Manager',
                        'city_finance' => 'Finance',
                        'city_support' => 'Support Opérations',
                        'city_agent' => 'Agent Ville',
                    ]),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Statut du Compte'),
                Tables\Filters\SelectFilter::make('city_id')
                    ->label('Filtrer par Ville')
                    ->relationship('city', 'name', modifyQueryUsing: fn (Builder $query) => FilamentCityScope::scopeByCity($query, 'cities.id'))
                    ->visible(fn (): bool => ! FilamentCityScope::isLocal()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Voir'),
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
            'index' => Pages\ListAdministrators::route('/'),
            'create' => Pages\CreateAdministrator::route('/create'),
            'edit' => Pages\EditAdministrator::route('/{record}/edit'),
        ];
    }
}
