<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ActivityLogResource\Pages;
use App\Models\ActivityLog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Ressource Filament du Journal d'Audit (Immuable).
 * Traçabilité de toutes les actions financières, de sécurité et d'administration.
 */
class ActivityLogResource extends Resource
{
    protected static ?string $model = ActivityLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Journal d\'Audit';

    protected static ?string $modelLabel = 'Entrée d\'Audit';

    protected static ?string $pluralModelLabel = 'Journal d\'Audit (Logs)';

    protected static ?int $navigationSort = 95;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->hasHabilitationTab('activity_logs');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Détails de l\'Action Auditée')
                    ->schema([
                        Forms\Components\TextInput::make('action')
                            ->label('Action')->disabled(),
                        Forms\Components\TextInput::make('severity')
                            ->label('Sévérité')->disabled(),
                        Forms\Components\TextInput::make('user.name')
                            ->label('Auteur')->disabled(),
                        Forms\Components\TextInput::make('ip_address')
                            ->label('Adresse IP')->disabled(),
                        Forms\Components\TextInput::make('created_at')
                            ->label('Horodatage')->disabled(),
                        Forms\Components\TextInput::make('entity_type')
                            ->label('Entité')->disabled(),
                    ])->columns(3),

                Forms\Components\Section::make('Données Contextuelles (Propriétés JSON)')
                    ->schema([
                        Forms\Components\Textarea::make('properties_display')
                            ->label('Propriétés & Anciennes/Nouvelles valeurs')
                            ->formatStateUsing(fn ($record) => json_encode($record?->properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                            ->rows(10)
                            ->disabled()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date & Heure')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('severity')
                    ->label('Sévérité')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'critical' => 'danger',
                        'warning' => 'warning',
                        'info' => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('action')
                    ->label('Action')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Auteur')
                    ->placeholder('Système')
                    ->searchable(),

                Tables\Columns\TextColumn::make('entity_type')
                    ->label('Entité')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('ip_address')
                    ->label('Adresse IP')
                    ->limit(15),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('severity')
                    ->label('Niveau de Sévérité')
                    ->options([
                        'critical' => '🚨 Critique (Finances / Sécurité)',
                        'warning' => '⚠️ Avertissement (Modifications)',
                        'info' => 'ℹ️ Information',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivityLogs::route('/'),
        ];
    }
}
