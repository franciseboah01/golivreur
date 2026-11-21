<?php

namespace App\Filament\Resources;

use App\Filament\Components\ImageUploadField;
use App\Filament\Components\SearchableSelect;
use App\Filament\Resources\ClientResource\Pages;
use App\Filament\Support\FilamentCityScope;
use App\Models\ActivityLog;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Ressource Filament dédiée aux Clients GoLivreur.
 * Permet au Support et aux Managers de consulter la liste des clients et de gérer leur statut d'activité.
 */
class ClientResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Opérations GoShop';

    protected static ?string $navigationLabel = 'Clients';

    protected static ?string $modelLabel = 'Client';

    protected static ?string $pluralModelLabel = 'Clients GoLivreur';

    protected static ?int $navigationSort = 5;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->hasHabilitationTab('clients');
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
        $query = parent::getEloquentQuery()
            ->where('role', 'client')
            ->latest();

        return FilamentCityScope::scopeByCity($query);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Fiche Client')
                    ->schema([
                        FilamentCityScope::configureCitySelect(
                            SearchableSelect::make('city_id')->label('Ville de rattachement')
                        )
                            ->required(),
                        SearchableSelect::make('language')
                            ->label('Langue préférée')
                            ->options(SearchableSelect::sortedOptions([
                                'en' => 'Anglais (EN)',
                                'fr' => 'Français (FR)',
                            ]))
                            ->default('fr'),
                        Forms\Components\TextInput::make('name')
                            ->label('Nom complet')
                            ->required(),
                        Forms\Components\TextInput::make('phone')
                            ->label('Numéro de téléphone')
                            ->required(),
                        Forms\Components\TextInput::make('email')
                            ->label('Adresse email')
                            ->email()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('default_address')
                            ->label('Adresse de livraison par défaut')
                            ->maxLength(255),
                        ImageUploadField::make('photo_path', 'profils', 'Photo de profil')
                            ->columnSpanFull(),
                        Forms\Components\Toggle::make('notifications_enabled')
                            ->label('Notifications activées')
                            ->default(true),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Compte Actif')
                            ->required(),
                        Forms\Components\TextInput::make('admin_latest_otp_code')
                            ->label('Dernier code OTP (Connexion / Réinitialisation)')
                            ->formatStateUsing(fn (?User $record): string => $record !== null && $record->admin_latest_otp_code !== null ? $record->admin_latest_otp_code : 'Aucun OTP récent')
                            ->disabled()
                            ->dehydrated(false),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('photo_path')
                    ->label('Photo')
                    ->disk('public')
                    ->circular()
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Client')
                    ->weight('bold')
                    ->searchable(),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Téléphone')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('admin_latest_otp_code')
                    ->label('Dernier OTP')
                    ->badge()
                    ->color('warning')
                    ->fontFamily('mono')
                    ->copyable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('default_address')
                    ->label('Adresse par défaut')
                    ->limit(30)
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('city.name')
                    ->label('Ville')
                    ->badge(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Statut')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Inscrit le')
                    ->dateTime('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Statut du compte')
                    ->trueLabel('Actifs uniquement')
                    ->falseLabel('Désactivés uniquement'),
                Tables\Filters\SelectFilter::make('city_id')
                    ->label('Ville')
                    ->relationship('city', 'name', modifyQueryUsing: fn (Builder $query) => FilamentCityScope::scopeByCity($query, 'cities.id'))
                    ->visible(fn (): bool => ! FilamentCityScope::isLocal()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Détail'),
                Tables\Actions\EditAction::make()->label('Modifier'),
                Tables\Actions\Action::make('toggleActive')
                    ->label(fn (User $record): string => $record->is_active ? 'Désactiver' : 'Réactiver')
                    ->icon(fn (User $record): string => $record->is_active ? 'heroicon-m-no-symbol' : 'heroicon-m-check-circle')
                    ->color(fn (User $record): string => $record->is_active ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $record): string => $record->is_active ? "Désactiver le compte de {$record->name}" : "Réactiver le compte de {$record->name}")
                    ->modalDescription(fn (User $record): string => $record->is_active
                        ? 'Le client ne pourra plus se connecter ni commander sur GoLivreur.'
                        : 'Le compte sera rétabli avec accès complet.')
                    ->action(function (User $record): void {
                        FilamentCityScope::authorizeRecord($record);

                        $newStatus = ! $record->is_active;
                        $record->update(['is_active' => $newStatus]);

                        ActivityLog::create([
                            'user_id' => auth()->id(),
                            'action' => $newStatus ? 'client_activated' : 'client_deactivated',
                            'entity_type' => 'user',
                            'entity_id' => $record->id,
                            'properties' => ['phone' => $record->phone, 'new_status' => $newStatus],
                            'ip_address' => request()->ip(),
                            'severity' => 'warning',
                        ]);

                        Notification::make()
                            ->title("Client {$record->name} ".($newStatus ? 'réactivé' : 'désactivé'))
                            ->color($newStatus ? 'success' : 'danger')
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClients::route('/'),
        ];
    }
}
