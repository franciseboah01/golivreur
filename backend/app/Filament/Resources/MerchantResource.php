<?php

namespace App\Filament\Resources;

use App\Filament\Components\EmojiSelectField;
use App\Filament\Components\ImageUploadField;
use App\Filament\Components\SearchableSelect;
use App\Filament\Resources\MerchantResource\Pages;
use App\Models\{ActivityLog, Merchant, MerchantCategory, User};
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;

class MerchantResource extends Resource
{
    protected static ?string $model = Merchant::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationGroup = 'Partenaires';

    protected static ?string $navigationLabel = 'Commerces Partenaires';

    protected static ?string $modelLabel = 'Commerce Partenaire';

    protected static ?string $pluralModelLabel = 'Commerces Partenaires';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        $user = auth()->user();
        return $user !== null && $user->hasHabilitationTab('merchants');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Identité & Localisation du Commerce')
                    ->description('Renseignez le compte gestionnaire, la ville, la catégorie, l\'emoji/logo et la photo du commerce.')
                    ->schema([
                        // Listes déroulantes remontées en haut du formulaire avec recherche live et tri alphabétique
                        SearchableSelect::make('user_id')
                            ->label('Compte Gestionnaire')
                            ->relationship('user', 'name', modifyQueryUsing: fn (Builder $query) => $query->where('role', 'merchant')->orWhereDoesntHave('merchant')->orderBy('name'))
                            ->getOptionLabelFromRecordUsing(fn (User $record): string => "{$record->name} (" . ($record->phone ?? 'Sans téléphone') . ")")
                            ->required()
                            ->helperText('Sélectionnez un compte gestionnaire ou créez-en un nouveau via le bouton +.')
                            ->createOptionForm([
                                Forms\Components\TextInput::make('name')
                                    ->label('Nom et Prénom du Gérant')
                                    ->required()
                                    ->maxLength(100),
                                Forms\Components\TextInput::make('phone')
                                    ->label('Téléphone Professionnel (Identifiant)')
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
                                    ->minLength(8)
                                    ->dehydrateStateUsing(fn ($state) => Hash::make($state)),
                            ])
                            ->createOptionUsing(function (array $data): int {
                                $user = User::create([
                                    'name' => $data['name'],
                                    'phone' => $data['phone'],
                                    'email' => $data['email'],
                                    'password' => Hash::make($data['password']),
                                    'role' => 'merchant',
                                    'is_active' => true,
                                    'phone_verified_at' => now(),
                                ]);
                                return $user->id;
                            }),

                        SearchableSelect::make('city_id')
                            ->label('Ville d\'implantation')
                            ->relationship('city', 'name', modifyQueryUsing: fn (Builder $query) => $query->orderBy('name'))
                            ->required(),

                        SearchableSelect::make('category')
                            ->label('Catégorie d\'activité')
                            ->options(fn () => MerchantCategory::where('is_active', true)->orderBy('name')->pluck('name', 'slug')->toArray())
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (?string $state, Forms\Get $get, Forms\Set $set) {
                                if ($state && empty($get('emoji'))) {
                                    $catEmoji = MerchantCategory::where('slug', $state)->value('emoji');
                                    if ($catEmoji) {
                                        $set('emoji', $catEmoji);
                                    }
                                }
                            })
                            ->createOptionForm([
                                EmojiSelectField::make('emoji', 'Emoji de la catégorie'),
                                Forms\Components\TextInput::make('name')
                                    ->label('Nom de la nouvelle catégorie')
                                    ->required()
                                    ->maxLength(100),
                                Forms\Components\TextInput::make('slug')
                                    ->label('Identifiant unique (slug)')
                                    ->required()
                                    ->maxLength(100),
                                ImageUploadField::make('logo_path', 'categories/logos', 'Logo de la catégorie (optionnel)'),
                                Forms\Components\Textarea::make('description')
                                    ->label('Description'),
                            ])
                            ->createOptionUsing(function (array $data): string {
                                $slug = \Illuminate\Support\Str::slug($data['slug'] ?? $data['name']);
                                $cat = MerchantCategory::create([
                                    'name' => $data['name'],
                                    'slug' => $slug,
                                    'emoji' => $data['emoji'] ?? '🏪',
                                    'logo_path' => $data['logo_path'] ?? null,
                                    'description' => $data['description'] ?? null,
                                    'is_active' => true,
                                ]);
                                return $cat->slug;
                            }),

                        EmojiSelectField::make('emoji', 'Emoji de la boutique'),

                        Forms\Components\TextInput::make('name')
                            ->label('Nom de l\'enseigne')
                            ->placeholder('Ex: Restaurant Le Carrefour')
                            ->required()
                            ->maxLength(100)
                            ->columnSpanFull(),

                        ImageUploadField::make(
                            'photo_path',
                            'commerces',
                            'Photo du commerce (Vitrine / Enseigne)'
                        ),

                        ImageUploadField::make(
                            'logo_path',
                            'commerces/logos',
                            'Logo de la boutique (Image carrée / Marque)'
                        ),

                        Forms\Components\Textarea::make('address')
                            ->label('Adresse physique')
                            ->placeholder('Quartier, repère, rue...')
                            ->required()
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('description')
                            ->label('Description de la boutique / spécialités')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])->columns(2),

                Forms\Components\Section::make('Paramètres d\'Exploitation & Commission')
                    ->schema([
                        Forms\Components\TextInput::make('commission_rate')
                            ->label('Taux de commission plateforme (%)')
                            ->numeric()
                            ->default(15)
                            ->required(),
                        Forms\Components\TextInput::make('prep_time_minutes')
                            ->label('Temps de préparation moyen (minutes)')
                            ->numeric()
                            ->default(20)
                            ->required(),
                        Forms\Components\Toggle::make('is_open')
                            ->label('Commerce actuellement ouvert aux commandes'),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Compte approuvé et actif'),
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
                    ->defaultImageUrl(url('images/placeholder-merchant.png')),

                Tables\Columns\ImageColumn::make('logo_path')
                    ->label('Logo')
                    ->disk('public')
                    ->circular()
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('emoji')
                    ->label('Emoji')
                    ->formatStateUsing(fn (?string $state, Merchant $record): string => $state ?: $record->resolved_emoji)
                    ->extraAttributes(['class' => 'text-xl']),

                Tables\Columns\TextColumn::make('name')
                    ->label('Enseigne')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Gestionnaire')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.phone')
                    ->label('Téléphone')
                    ->searchable(),

                Tables\Columns\TextColumn::make('city.name')
                    ->label('Ville')
                    ->sortable(),

                Tables\Columns\TextColumn::make('category')
                    ->label('Catégorie')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => MerchantCategory::where('slug', $state)->value('name') ?? ucfirst($state)),

                Tables\Columns\IconColumn::make('is_open')
                    ->label('Ouvert')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Actif')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-badge')
                    ->falseIcon('heroicon-o-minus-circle')
                    ->trueColor('success')
                    ->falseColor('gray'),

                Tables\Columns\TextColumn::make('commission_rate')
                    ->label('Commission')
                    ->formatStateUsing(fn ($state) => $state . ' %')
                    ->sortable(),

                Tables\Columns\TextColumn::make('prep_time_minutes')
                    ->label('Préparation')
                    ->formatStateUsing(fn ($state) => $state . ' min')
                    ->sortable(),

                Tables\Columns\TextColumn::make('average_rating')
                    ->label('Note')
                    ->formatStateUsing(fn ($state) => '★ ' . number_format((float) $state, 1))
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_ratings')
                    ->label('Avis')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date Inscription')
                    ->dateTime('d/m/Y')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'asc')
            ->defaultKeySort(true)
            ->filters([
                Tables\Filters\TernaryFilter::make('is_open')
                    ->label('Ouverture')
                    ->trueLabel('Commerces Ouverts')
                    ->falseLabel('Commerces Fermés'),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Statut Compte')
                    ->trueLabel('Commerces Validés')
                    ->falseLabel('Commerces En Attente'),
                Tables\Filters\SelectFilter::make('category')
                    ->label('Catégorie')
                    ->options(fn () => MerchantCategory::pluck('name', 'slug')->toArray()),
                Tables\Filters\SelectFilter::make('city_id')
                    ->label('Filtrer par Ville')
                    ->relationship('city', 'name'),
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
                    ->label(fn (Merchant $record): string => $record->is_active ? 'Désactiver' : 'Activer')
                    ->icon(fn (Merchant $record): string => $record->is_active ? 'heroicon-m-no-symbol' : 'heroicon-m-check-circle')
                    ->color(fn (Merchant $record): string => $record->is_active ? 'danger' : 'success')
                    ->form(fn (Merchant $record): array => $record->is_active ? [] : [
                        Forms\Components\TextInput::make('commission_rate')
                            ->label('Taux de commission négocié (%)')
                            ->numeric()
                            ->default($record->commission_rate ?? 15.0)
                            ->required(),
                    ])
                    ->requiresConfirmation()
                    ->modalHeading(fn (Merchant $record): string => $record->is_active ? "Désactiver {$record->name}" : "Activer {$record->name}")
                    ->action(function (Merchant $record, array $data): void {
                        $newStatus = ! $record->is_active;
                        $updateData = ['is_active' => $newStatus];

                        if ($newStatus) {
                            $updateData['activated_at'] = now();
                            $updateData['activated_by'] = auth()->id();
                            if (isset($data['commission_rate'])) {
                                $updateData['commission_rate'] = (float) $data['commission_rate'];
                            }
                        }

                        $record->update($updateData);

                        ActivityLog::create([
                            'user_id'     => auth()->id(),
                            'action'      => $newStatus ? 'merchant_activated' : 'merchant_deactivated',
                            'entity_type' => 'merchant',
                            'entity_id'   => $record->id,
                            'properties'  => $updateData,
                            'ip_address'  => request()->ip(),
                            'severity'    => 'warning',
                        ]);

                        Notification::make()
                            ->title("Commerce {$record->name} " . ($newStatus ? 'activé' : 'désactivé'))
                            ->color($newStatus ? 'success' : 'danger')
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
            'index' => Pages\ListMerchants::route('/'),
            'create' => Pages\CreateMerchant::route('/create'),
            'edit' => Pages\EditMerchant::route('/{record}/edit'),
        ];
    }
}
