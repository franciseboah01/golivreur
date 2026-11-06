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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereIn('role', User::ADMIN_ROLES);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Identité & Accès d\'Administration')
                    ->description('Gestion des comptes ayant accès au tableau de bord et à la gestion opérationnelle')
                    ->schema([
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
                        Forms\Components\Select::make('role')
                            ->label('Niveau d\'Habilitation & Rôle')
                            ->options([
                                'super_admin' => 'Super Administrateur (Accès Total Plateforme)',
                                'dg_national' => 'Directeur Général National',
                                'national_finance' => 'Responsable Finance National',
                                'national_tech' => 'Responsable Technique & IT',
                                'national_support' => 'Responsable Support National',
                                'city_manager' => 'City Manager (Gestionnaire de Ville)',
                                'city_finance' => 'Responsable Finance Ville',
                                'city_support' => 'Support Opérations & Déblocages Ville',
                                'city_agent' => 'Agent Terrain Ville',
                            ])
                            ->required()
                            ->default('city_agent'),
                        Forms\Components\Select::make('city_id')
                            ->label('Ville d\'affectation')
                            ->relationship('city', 'name')
                            ->helperText('Laisser vide pour les fonctions à compétence nationale'),
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

