<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoleHabilitationResource\Pages;
use App\Models\RoleHabilitation;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class RoleHabilitationResource extends Resource
{
    protected static ?string $model = RoleHabilitation::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Niveaux d\'Habilitation & Rôles';

    protected static ?string $modelLabel = 'Niveau d\'Habilitation';

    protected static ?string $pluralModelLabel = 'Niveaux d\'Habilitation & Rôles';

    protected static ?int $navigationSort = 9;

    /**
     * Seul le Super Administrateur peut voir et gérer les Niveaux d'Habilitation.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();
        return $user !== null && $user->role === 'super_admin';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informations du Niveau d\'Habilitation')
                    ->description('Définissez l\'intitulé et la portée de ce niveau de permissions administratives.')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nom du Niveau / Rôle')
                            ->placeholder('Ex: Responsable Finance National, Coordinateur Support...')
                            ->required()
                            ->maxLength(120)
                            ->unique(RoleHabilitation::class, 'name', ignoreRecord: true),

                        Forms\Components\Textarea::make('description')
                            ->label('Description opérationnelle')
                            ->placeholder('Précisez les responsabilités associées à ce niveau...')
                            ->rows(2)
                            ->maxLength(500),
                    ]),

                Forms\Components\Section::make('Onglets & Sections Autorisés')
                    ->description('Cochez les onglets auxquels ce niveau donne accès. Les administrateurs héritent automatiquement de cette sélection.')
                    ->schema([
                        Forms\Components\CheckboxList::make('onglets')
                            ->label('Sélectionnez les onglets autorisés')
                            ->options(RoleHabilitation::getOngletOptions())
                            ->descriptions(RoleHabilitation::getOngletDescriptions())
                            ->columns(2)
                            ->gridDirection('row')
                            ->bulkToggleable()
                            ->required()
                            ->minItems(1, 'Veuillez cocher au moins un onglet autorisé.'),
                    ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Détails du Niveau d\'Habilitation')
                    ->schema([
                        Infolists\Components\TextEntry::make('name')
                            ->label('Nom du Niveau'),
                        Infolists\Components\TextEntry::make('slug')
                            ->label('Identifiant système (Slug)')
                            ->badge()
                            ->color('gray'),
                        Infolists\Components\TextEntry::make('description')
                            ->label('Description')
                            ->placeholder('Aucune description fournie')
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('users_count')
                            ->label('Administrateurs affectés')
                            ->state(fn (RoleHabilitation $record): int => $record->users()->count())
                            ->badge()
                            ->color('info'),
                    ])->columns(3),

                Infolists\Components\Section::make('Onglets Autorisés')
                    ->description('Onglets et fonctionnalités accessibles pour les administrateurs ayant ce niveau.')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('onglets')
                            ->label('Liste des onglets')
                            ->schema([
                                Infolists\Components\TextEntry::make('')
                                    ->formatStateUsing(function ($state): string {
                                        $meta = RoleHabilitation::AVAILABLE_ONGLETS[$state] ?? null;
                                        if ($meta) {
                                            return "{$meta['label']} — [{$meta['group']}] : {$meta['description']}";
                                        }
                                        return (string) $state;
                                    })
                                    ->badge()
                                    ->color('success'),
                            ])
                            ->columns(1),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Niveau / Rôle')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->limit(60)
                    ->tooltip(fn ($record) => $record->description)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('onglets_count')
                    ->label('Onglets Autorisés')
                    ->state(fn (RoleHabilitation $record): int => count($record->onglets ?? []))
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state >= 12 => 'success',
                        $state >= 6 => 'warning',
                        default => 'gray',
                    })
                    ->suffix(' onglet(s)'),

                Tables\Columns\TextColumn::make('users_count')
                    ->label('Admins Affectés')
                    ->counts('users')
                    ->badge()
                    ->color('info')
                    ->suffix(' admin(s)'),

                Tables\Columns\IconColumn::make('is_system')
                    ->label('Système')
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->falseIcon('heroicon-o-pencil')
                    ->trueColor('warning')
                    ->falseColor('gray'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'asc')
            ->actions([
                Tables\Actions\ViewAction::make()->label('Voir'),
                Tables\Actions\EditAction::make()->label('Modifier'),
                Tables\Actions\DeleteAction::make()
                    ->label('Supprimer')
                    ->hidden(fn (RoleHabilitation $record): bool => $record->is_system || $record->slug === 'super_admin' || $record->users()->count() > 0),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // Pas de suppression de masse pour protéger les habilitations
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
            'index' => Pages\ListRoleHabilitations::route('/'),
            'create' => Pages\CreateRoleHabilitation::route('/create'),
            'view' => Pages\ViewRoleHabilitation::route('/{record}'),
            'edit' => Pages\EditRoleHabilitation::route('/{record}/edit'),
        ];
    }
}
