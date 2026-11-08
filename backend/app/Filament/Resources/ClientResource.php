<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClientResource\Pages;
use App\Models\{ActivityLog, User};
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
        return $user && in_array($user->role, [
            'city_support',
            'city_manager',
            'national_support',
            'super_admin',
            'dg_national',
        ], true);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('role', 'client')
            ->latest();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Fiche Client')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nom complet')
                            ->required(),
                        Forms\Components\TextInput::make('phone')
                            ->label('Numéro de téléphone')
                            ->required(),
                        Forms\Components\Select::make('city_id')
                            ->label('Ville de rattachement')
                            ->relationship('city', 'name')
                            ->required(),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Compte Actif')
                            ->required(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Client')
                    ->weight('bold')
                    ->searchable(),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Téléphone')
                    ->searchable()
                    ->copyable(),

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
                    ->relationship('city', 'name'),
            ])
            ->actions([
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
                        $newStatus = ! $record->is_active;
                        $record->update(['is_active' => $newStatus]);

                        ActivityLog::create([
                            'user_id'     => auth()->id(),
                            'action'      => $newStatus ? 'client_activated' : 'client_deactivated',
                            'entity_type' => 'user',
                            'entity_id'   => $record->id,
                            'properties'  => ['phone' => $record->phone, 'new_status' => $newStatus],
                            'ip_address'  => request()->ip(),
                            'severity'    => 'warning',
                        ]);

                        Notification::make()
                            ->title("Client {$record->name} " . ($newStatus ? 'réactivé' : 'désactivé'))
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

