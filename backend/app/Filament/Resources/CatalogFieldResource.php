<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CatalogFieldResource\Pages;
use App\Models\CatalogField;
use App\Services\CatalogSchemaService;
use Closure;
use DomainException;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CatalogFieldResource extends Resource
{
    protected static ?string $model = CatalogField::class;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'Partenaires';

    protected static ?string $navigationLabel = 'Dictionnaire de Champs';

    protected static ?string $modelLabel = 'Champ du Dictionnaire';

    protected static ?string $pluralModelLabel = 'Dictionnaire de Champs Produits';

    protected static ?int $navigationSort = 5;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->hasHabilitationTab('merchants');
    }

    public static function canDelete(Model $record): bool
    {
        if (! $record instanceof CatalogField) {
            return false;
        }

        return ! $record->isUsedByAnyProduct();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Définition du Champ (Bibliothèque Centrale)')
                    ->description('Définissez un champ réutilisable pour les catalogues produits. Utilisez toujours un libellé précis (ex: « Nom du produit », « Nom du plat », « Marque », « Contenance » — jamais juste « Nom »).')
                    ->schema([
                        Forms\Components\TextInput::make('label')
                            ->label('Libellé précis par défaut')
                            ->placeholder('Ex: Marque, Contenance, Ingrédients, Taille...')
                            ->required()
                            ->maxLength(120)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (string $operation, ?string $state, Set $set): void {
                                if ($operation === 'create' && is_string($state) && trim($state) !== '') {
                                    $set('code', Str::slug($state, '_'));
                                }
                            })
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (is_string($value) && app(CatalogSchemaService::class)->isForbiddenGenericLabel($value)) {
                                    $fail('Utilisez un libellé précis (ex: « Nom du produit » ou « Nom du plat »), jamais « Nom » seul.');
                                }
                            }),

                        Forms\Components\TextInput::make('code')
                            ->label('Code technique unique')
                            ->placeholder('Ex: brand, volume, ingredients...')
                            ->helperText('Lettres minuscules, chiffres et underscore (_). Non modifiable si le champ est déjà utilisé par un produit.')
                            ->required()
                            ->maxLength(80)
                            ->regex('/^[a-z][a-z0-9_]*$/')
                            ->unique(CatalogField::class, 'code', ignoreRecord: true)
                            ->disabled(fn (?CatalogField $record): bool => $record !== null && ($record->is_core || $record->isUsedByAnyProduct()))
                            ->dehydrated(),

                        Forms\Components\Select::make('field_type')
                            ->label('Type de saisie dans l\'App Business')
                            ->options(CatalogField::fieldTypeLabels())
                            ->default(CatalogField::TYPE_TEXT)
                            ->required()
                            ->live()
                            ->disabled(fn (?CatalogField $record): bool => $record !== null && $record->is_core)
                            ->dehydrated(),

                        Forms\Components\TextInput::make('default_order')
                            ->label('Ordre d\'affichage par défaut')
                            ->helperText('Plus le chiffre est petit, plus le champ apparaît haut (ex: 10 = Nom en 1er, 90 = Quantité en bas).')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(999)
                            ->default(60)
                            ->required(),

                        Forms\Components\TextInput::make('placeholder')
                            ->label('Texte d\'exemple (placeholder)')
                            ->placeholder('Ex: Nivea, 250 ml, XL...')
                            ->maxLength(200)
                            ->columnSpanFull(),

                        Forms\Components\TagsInput::make('select_options')
                            ->label('Choix proposés (Liste déroulante)')
                            ->placeholder('Ajouter une option puis Entrée (ex: S, M, L, XL)')
                            ->visible(fn (Get $get): bool => $get('field_type') === CatalogField::TYPE_SELECT)
                            ->required(fn (Get $get): bool => $get('field_type') === CatalogField::TYPE_SELECT)
                            ->columnSpanFull(),

                        Forms\Components\Toggle::make('is_universal_default')
                            ->label('Coché par défaut sur tout nouveau Type de Commerce')
                            ->helperText('Activer uniquement pour les champs universels (Nom, Description, Prix, Photo, Quantité).'),

                        Forms\Components\Toggle::make('is_required_default')
                            ->label('Obligatoire par défaut')
                            ->helperText('Le commerçant devra obligatoirement remplir ce champ.'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Champ actif dans la bibliothèque')
                            ->default(true)
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('default_order')
                    ->label('Ordre')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('label')
                    ->label('Libellé')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('code')
                    ->label('Code')
                    ->badge()
                    ->color('info')
                    ->searchable(),

                Tables\Columns\TextColumn::make('field_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => CatalogField::fieldTypeLabels()[$state] ?? $state)
                    ->color('warning'),

                Tables\Columns\IconColumn::make('is_universal_default')
                    ->label('Universel')
                    ->boolean()
                    ->trueIcon('heroicon-o-star')
                    ->falseIcon('heroicon-o-minus')
                    ->trueColor('warning')
                    ->falseColor('gray'),

                Tables\Columns\TextColumn::make('merchant_categories_count')
                    ->counts('merchantCategories')
                    ->label('Types liés')
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('products_usage')
                    ->label('Produits associés')
                    ->badge()
                    ->state(fn (CatalogField $record): int => $record->countProductsUsingField())
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'success')
                    ->tooltip(fn (CatalogField $record): string => $record->isUsedByAnyProduct()
                        ? 'Suppression bloquée : utilisé par au moins un produit existant.'
                        : 'Aucun produit associé : suppression autorisée.'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Actif')
                    ->boolean(),
            ])
            ->defaultSort('default_order', 'asc')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_universal_default')
                    ->label('Champs universels'),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Statut actif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Modifier'),
                Tables\Actions\DeleteAction::make()
                    ->label('Supprimer')
                    ->disabled(fn (CatalogField $record): bool => $record->isUsedByAnyProduct())
                    ->tooltip(fn (CatalogField $record): ?string => $record->isUsedByAnyProduct()
                        ? 'Impossible de supprimer : ce champ est déjà utilisé par un produit.'
                        : null)
                    ->action(function (CatalogField $record): void {
                        try {
                            $record->delete();
                            Notification::make()
                                ->title("Champ « {$record->label} » supprimé de la bibliothèque.")
                                ->success()
                                ->send();
                        } catch (DomainException $e) {
                            Notification::make()
                                ->title('Suppression impossible')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCatalogFields::route('/'),
            'create' => Pages\CreateCatalogField::route('/create'),
            'edit' => Pages\EditCatalogField::route('/{record}/edit'),
        ];
    }
}
