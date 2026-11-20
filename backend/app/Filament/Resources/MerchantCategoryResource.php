<?php

namespace App\Filament\Resources;

use App\Filament\Components\EmojiSelectField;
use App\Filament\Components\ImageUploadField;
use App\Filament\Resources\MerchantCategoryResource\Pages;
use App\Models\CatalogField;
use App\Models\MerchantCategory;
use App\Services\CatalogSchemaService;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class MerchantCategoryResource extends Resource
{
    protected static ?string $model = MerchantCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Partenaires';

    protected static ?string $navigationLabel = 'Types de Commerce';

    protected static ?string $modelLabel = 'Type de Commerce';

    protected static ?string $pluralModelLabel = 'Types de Commerce';

    protected static ?int $navigationSort = 4;

    protected static bool $shouldRegisterNavigation = true;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->hasHabilitationTab('merchants');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informations & Identité Visuelle de la Catégorie')
                    ->description('Définissez une catégorie commerciale, son emoji et/ou son logo visibles dans l\'application mobile.')
                    ->schema([
                        // Liste déroulante remontée en haut avec recherche live
                        EmojiSelectField::make('emoji', 'Emoji représentatif (recommandé)')
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('name')
                            ->label('Nom de la catégorie')
                            ->required()
                            ->maxLength(100)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (string $operation, $state, Set $set) {
                                if ($operation === 'create') {
                                    $set('slug', Str::slug($state));
                                }
                            }),

                        Forms\Components\TextInput::make('slug')
                            ->label('Identifiant système (slug)')
                            ->required()
                            ->maxLength(100)
                            ->unique(MerchantCategory::class, 'slug', ignoreRecord: true),

                        ImageUploadField::make(
                            'logo_path',
                            'categories/logos',
                            'Logo de la catégorie (image optionnelle)'
                        )->columnSpanFull(),

                        Forms\Components\Textarea::make('description')
                            ->label('Description & Types de produits')
                            ->placeholder('Ex: Plats chauds, maquis traditionnels, fast-food et boissons fraîches')
                            ->columnSpanFull(),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Type de commerce actif (visible dans l\'admin et sur l\'application mobile)')
                            ->default(true),
                    ])->columns(2),

                Forms\Components\Section::make('Champs du Catalogue Produits (Cocher & Ordonner)')
                    ->description('Cochez les champs autorisés pour ce Type de Commerce et réordonnez-les par glisser-déposer (ou flèches ↑ ↓). L\'ordre défini ici est l\'ordre exact affiché dans GL Business. Tout champ décoché est bloqué côté API (erreur 403).')
                    ->schema([
                        Forms\Components\Repeater::make('catalog_field_config')
                            ->label('Champs de la bibliothèque centrale')
                            ->reorderable(true)
                            ->reorderableWithButtons()
                            ->addable(false)
                            ->deletable(false)
                            ->collapsible()
                            ->collapsed(false)
                            ->default(fn (): array => app(CatalogSchemaService::class)->buildDefaultConfigRowsForNewCategory())
                            ->itemLabel(function (array $state): string {
                                $enabled = ! empty($state['enabled']);
                                $label = is_string($state['custom_label'] ?? null) && trim($state['custom_label']) !== ''
                                    ? trim($state['custom_label'])
                                    : (string) ($state['default_label'] ?? $state['code'] ?? 'Champ');
                                $type = (string) ($state['field_type'] ?? 'text');
                                $typeLabel = CatalogField::fieldTypeLabels()[$type] ?? $type;
                                $req = ! empty($state['is_required']) ? ' • Obligatoire' : '';

                                return ($enabled ? '[COCHÉ] ' : '[Décoché] ')."{$label} ({$typeLabel}{$req})";
                            })
                            ->schema([
                                Forms\Components\Hidden::make('catalog_field_id'),
                                Forms\Components\Hidden::make('code'),
                                Forms\Components\Hidden::make('default_label'),
                                Forms\Components\Hidden::make('field_type'),

                                Forms\Components\Checkbox::make('enabled')
                                    ->label('Autoriser ce champ')
                                    ->live()
                                    ->columnSpan(3),

                                Forms\Components\TextInput::make('custom_label')
                                    ->label('Libellé précis affiché au commerçant')
                                    ->placeholder('Ex: Nom du plat, Nom du produit, Marque...')
                                    ->required(fn (Get $get): bool => ! empty($get('enabled')))
                                    ->maxLength(120)
                                    ->disabled(fn (Get $get): bool => empty($get('enabled')))
                                    ->dehydrated()
                                    ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                        if (empty($get('enabled'))) {
                                            return;
                                        }
                                        if (is_string($value) && app(CatalogSchemaService::class)->isForbiddenGenericLabel($value)) {
                                            $fail('Utilisez un libellé précis (ex: « Nom du plat » ou « Nom du produit »), jamais « Nom » seul.');
                                        }
                                    })
                                    ->columnSpan(5),

                                Forms\Components\Placeholder::make('code_badge')
                                    ->label('Code / Type')
                                    ->content(function (Get $get): string {
                                        $code = (string) ($get('code') ?? '');
                                        $type = (string) ($get('field_type') ?? 'text');
                                        $typeLabel = CatalogField::fieldTypeLabels()[$type] ?? $type;

                                        return "{$code} ({$typeLabel})";
                                    })
                                    ->columnSpan(2),

                                Forms\Components\Toggle::make('is_required')
                                    ->label('Obligatoire')
                                    ->disabled(fn (Get $get): bool => empty($get('enabled')))
                                    ->dehydrated()
                                    ->columnSpan(2),
                            ])
                            ->columns(12)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('rowIndex')
                    ->rowIndex()
                    ->label('N°'),

                Tables\Columns\ImageColumn::make('logo_path')
                    ->label('Logo')
                    ->disk('public')
                    ->circular()
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('emoji')
                    ->label('Emoji')
                    ->formatStateUsing(fn (?string $state): string => $state ?: '🏪')
                    ->extraAttributes(['class' => 'text-xl']),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nom de la Catégorie')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('slug')
                    ->label('Identifiant (slug)')
                    ->badge()
                    ->color('gray')
                    ->searchable(),

                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->limit(45)
                    ->placeholder('Aucune description'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\TextColumn::make('catalog_fields_count')
                    ->counts('catalogFields')
                    ->label('Champs actifs')
                    ->badge()
                    ->color('success')
                    ->sortable(),

                Tables\Columns\TextColumn::make('merchants_count')
                    ->counts('merchants')
                    ->label('Commerces affiliés')
                    ->badge()
                    ->color('info')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créée le')
                    ->dateTime('d/m/Y')
                    ->sortable(),
            ])
            ->defaultSort('name', 'asc')
            ->defaultKeySort(true)
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Statut')
                    ->trueLabel('Catégories Actives')
                    ->falseLabel('Catégories Inactives'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Modifier'),
                Tables\Actions\DeleteAction::make()->label('Supprimer'),
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
            'index' => Pages\ListMerchantCategories::route('/'),
            'create' => Pages\CreateMerchantCategory::route('/create'),
            'edit' => Pages\EditMerchantCategory::route('/{record}/edit'),
        ];
    }
}
