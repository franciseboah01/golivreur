<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OtpCodeResource\Pages;
use App\Models\OtpCode;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Ressource Filament permettant à l'administration de consulter les codes OTP
 * (connexion, inscription, réinitialisation mot de passe, vérification support, etc.).
 */
class OtpCodeResource extends Resource
{
    protected static ?string $model = OtpCode::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Codes OTP Connexion';

    protected static ?string $modelLabel = 'Code OTP';

    protected static ?string $pluralModelLabel = 'Codes OTP (Connexion & Vérification)';

    protected static ?int $navigationSort = 10;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && (
            $user->hasHabilitationTab('clients')
            || $user->hasHabilitationTab('disputes')
            || $user->hasHabilitationTab('activity_logs')
        );
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Détails du Code OTP')
                    ->schema([
                        Forms\Components\TextInput::make('phone')
                            ->label('Téléphone / Cible')
                            ->disabled(),
                        Forms\Components\TextInput::make('admin_otp_code')
                            ->label('Code OTP en clair')
                            ->formatStateUsing(fn (?OtpCode $record): string => $record !== null && $record->admin_otp_code !== null ? $record->admin_otp_code : 'Non disponible')
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\TextInput::make('purpose')
                            ->label('Motif / Usage')
                            ->disabled(),
                        Forms\Components\TextInput::make('attempts')
                            ->label('Tentatives')
                            ->disabled(),
                        Forms\Components\Toggle::make('is_used')
                            ->label('Déjà utilisé / invalidé')
                            ->disabled(),
                        Forms\Components\DateTimePicker::make('expires_at')
                            ->label('Date d\'expiration')
                            ->disabled(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Téléphone / Cible')
                    ->searchable()
                    ->copyable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('admin_otp_code')
                    ->label('Code OTP')
                    ->badge()
                    ->color('warning')
                    ->fontFamily('mono')
                    ->copyable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('purpose')
                    ->label('Type / Motif')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'login' => 'primary',
                        'register' => 'success',
                        'password_reset' => 'warning',
                        'admin_2fa' => 'danger',
                        'support_identity_verify' => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'login' => 'Connexion (login)',
                        'register' => 'Inscription (register)',
                        'password_reset' => 'Mot de passe oublié',
                        'admin_2fa' => '2FA Admin',
                        'support_identity_verify' => 'Vérification Support',
                        default => $state,
                    })
                    ->searchable(),

                Tables\Columns\TextColumn::make('attempts')
                    ->label('Essais')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_used')
                    ->label('Utilisé')
                    ->boolean(),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Expire le')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('purpose')
                    ->label('Type d\'OTP')
                    ->options([
                        'login' => 'Connexion',
                        'register' => 'Inscription',
                        'password_reset' => 'Mot de passe oublié',
                        'support_identity_verify' => 'Vérification Support',
                        'admin_2fa' => '2FA Admin',
                    ]),
                Tables\Filters\TernaryFilter::make('is_used')
                    ->label('État')
                    ->trueLabel('Utilisés / Invalidés')
                    ->falseLabel('Actifs (Non utilisés)'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Détail'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOtpCodes::route('/'),
        ];
    }
}
