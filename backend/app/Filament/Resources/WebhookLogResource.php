<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WebhookLogResource\Pages;
use App\Models\WebhookLog;
use App\Services\WebhookReplayService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Ressource Filament de surveillance et de rejeu des Webhooks (Hub2, CinetPay).
 * Permet au Super Admin et à l'équipe technique d'inspecter les payloads et de relancer les échecs.
 */
class WebhookLogResource extends Resource
{
    protected static ?string $model = WebhookLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?string $navigationGroup = 'Paramètres & Système';

    protected static ?string $navigationLabel = 'Webhooks Paiements';

    protected static ?string $modelLabel = 'Webhook Log';

    protected static ?string $pluralModelLabel = 'Logs Webhooks & Rejeu';

    protected static ?int $navigationSort = 90;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->hasHabilitationTab('webhook_logs');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Détails de la Réception')
                    ->schema([
                        Forms\Components\TextInput::make('source')
                            ->label('Passerelle')->disabled(),
                        Forms\Components\TextInput::make('event_type')
                            ->label('Événement')->disabled(),
                        Forms\Components\Toggle::make('signature_valid')
                            ->label('Signature HMAC Valide')->disabled(),
                        Forms\Components\TextInput::make('status')
                            ->label('Statut du traitement')->disabled(),
                        Forms\Components\TextInput::make('retry_count')
                            ->label('Tentatives de rejeu')->disabled(),
                        Forms\Components\TextInput::make('created_at')
                            ->label('Date réception')->disabled(),
                        Forms\Components\Textarea::make('error_message')
                            ->label('Dernière erreur')
                            ->disabled()
                            ->columnSpanFull(),
                    ])->columns(3),

                Forms\Components\Section::make('Données Brutes (Payload JSON)')
                    ->schema([
                        Forms\Components\Textarea::make('payload_display')
                            ->label('Contenu du Payload')
                            ->formatStateUsing(fn ($record) => json_encode($record?->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                            ->rows(15)
                            ->disabled()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Reçu le')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('source')
                    ->label('Source')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('event_type')
                    ->label('Type Événement')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\IconColumn::make('signature_valid')
                    ->label('Signature')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'processed' => 'success',
                        'pending' => 'warning',
                        'failed' => 'danger',
                        'rejected' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('retry_count')
                    ->label('Rejeux')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('error_message')
                    ->label('Erreur')
                    ->limit(30)
                    ->placeholder('-'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        'processed' => 'Traité avec succès',
                        'pending' => 'En attente',
                        'failed' => 'Échoué',
                        'rejected' => 'Rejeté (signature invalide)',
                    ]),
                Tables\Filters\SelectFilter::make('source')
                    ->label('Source')
                    ->options([
                        'hub2' => 'Hub2',
                        'cinetpay' => 'CinetPay',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('replay')
                    ->label('Rejouer')
                    ->icon('heroicon-m-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Rejouer le traitement de ce webhook')
                    ->modalDescription('Si le paiement était en échec ou en attente, le système va ré-exécuter le traitement des statuts.')
                    ->visible(fn (WebhookLog $record): bool => $record->signature_valid
                        && $record->status !== 'processed'
                        && WebhookReplayService::canReplay(auth()->user()))
                    ->action(function (WebhookLog $record): void {
                        $user = auth()->user();
                        if (! WebhookReplayService::canReplay($user)) {
                            abort(403, 'Seuls le Super Admin et le Responsable Technique National peuvent rejouer un webhook.');
                        }

                        try {
                            app(WebhookReplayService::class)->replay($user, $record);

                            Notification::make()
                                ->title("Webhook #{$record->id} mis en queue pour rejeu.")
                                ->success()
                                ->send();
                        } catch (HttpExceptionInterface $e) {
                            if ($e->getStatusCode() === 403) {
                                abort(403, $e->getMessage());
                            }

                            Notification::make()
                                ->title($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWebhookLogs::route('/'),
        ];
    }
}
