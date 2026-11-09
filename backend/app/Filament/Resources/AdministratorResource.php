<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AdministratorResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->whereIn('role', User::ADMIN_ROLES);

        $user = auth()->user();
        if ($user && $user->role === 'city_manager' && $user->city_id) {
            $query->where('city_id', $user->city_id);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Identité & Accès d\'Administration')
                    ->description('Gestion des comptes ayant accès au tableau de bord et à la gestion opérationnelle')
                    ->schema([
                        // Listes déroulantes en haut avec recherche live et tri alphabétique
                        Forms\Components\Select::make('role_habilitation_id')
                            ->label('Niveau d\'Habilitation & Rôle')
                            ->relationship('roleHabilitation', 'name', modifyQueryUsing: fn (Builder $query) => $query->orderBy('name'))
                            ->preload()
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                if ($state) {
                                    $level = \App\Models\RoleHabilitation::find($state);
                                    if ($level && $level->slug) {
                                        $set('role', $level->slug);
                                    }
                                }
                            })
                            ->helperText('Tous les onglets et accès du panneau sont automatiquement hérités selon ce niveau.'),
                        Forms\Components\Hidden::make('role')
                            ->default('city_agent')
                            ->dehydrateStateUsing(function ($state, Forms\Get $get) {
                                $levelId = $get('role_habilitation_id');
                                if ($levelId) {
                                    $level = \App\Models\RoleHabilitation::find($levelId);
                                    if ($level) {
                                        return $level->slug;
                                    }
                                }
                                return $state ?? 'admin';
                            }),
                        Forms\Components\Select::make('city_id')
                            ->label('Ville d\'affectation')
                            ->relationship('city', 'name', modifyQueryUsing: fn (Builder $query) => $query->orderBy('name'))
                            ->searchable()
                            ->preload()
                            ->helperText('Laisser vide pour les fonctions à compétence nationale'),
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
                    ->relationship('city', 'name'),
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

