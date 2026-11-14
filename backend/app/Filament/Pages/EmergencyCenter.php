<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\EmergencyStatsWidget;
use App\Models\City;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\User;
use App\Services\ActivityLogService;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class EmergencyCenter extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-bolt';

    protected static string $view = 'filament.pages.emergency-center';

    protected static ?string $navigationGroup = 'Autre';

    protected static ?string $navigationLabel = 'Déclencheurs d\'Urgences';

    protected static ?string $title = 'Centre d\'Urgences & Déclencheurs Rapides';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->hasHabilitationTab('emergency_center');
    }

    protected function getHeaderWidgets(): array
    {
        return [
            EmergencyStatsWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('activateAllRain')
                ->label('Activer Mode Pluie (Toutes les Villes)')
                ->icon('heroicon-m-cloud')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Activer le Mode Pluie sur toutes les zones')
                ->modalDescription('Cette action active immédiatement la majoration intempéries (+20% sur la livraison) pour l\'ensemble des villes opérationnelles.')
                ->modalSubmitActionLabel('Confirmer l\'activation')
                ->action(function (): void {
                    City::where('is_active', true)->update([
                        'rain_mode_active' => true,
                        'rain_mode_activated_at' => now(),
                    ]);

                    Notification::make()
                        ->title('Mode Pluie activé sur toutes les villes opérationnelles')
                        ->warning()
                        ->send();
                }),

            Actions\Action::make('deactivateAllRain')
                ->label('Désactiver Mode Pluie (Toutes les Villes)')
                ->icon('heroicon-m-sun')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Désactiver le Mode Pluie global')
                ->modalDescription('Rétablir les tarifs de livraison standard sur toutes les zones.')
                ->modalSubmitActionLabel('Confirmer la désactivation')
                ->action(function (): void {
                    City::query()->update([
                        'rain_mode_active' => false,
                        'rain_mode_activated_at' => null,
                    ]);

                    Notification::make()
                        ->title('Mode Pluie désactivé sur toutes les villes')
                        ->success()
                        ->send();
                }),

            Actions\Action::make('unlockAllCodes')
                ->label('Débloquer Tous les Codes OTP')
                ->icon('heroicon-m-lock-open')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Déblocage global des codes de validation')
                ->modalDescription('Cette action réinitialise les tentatives erronées de toutes les commandes et colis actuellement bloqués.')
                ->modalSubmitActionLabel('Débloquer tous les codes')
                ->action(function (): void {
                    $ordersUpdated = Order::where('confirmation_locked', true)->update([
                        'confirmation_locked' => false,
                        'confirmation_attempts' => 0,
                        'confirmation_unlocked_by' => auth()->id(),
                    ]);

                    $parcelsUpdated = Parcel::where('sender_code_locked', true)
                        ->orWhere('recipient_code_locked', true)
                        ->update([
                            'sender_code_locked' => false,
                            'recipient_code_locked' => false,
                            'sender_code_unlocked_by' => auth()->id(),
                            'recipient_code_unlocked_by' => auth()->id(),
                        ]);

                    $total = $ordersUpdated + $parcelsUpdated;

                    Notification::make()
                        ->title("Déblocage terminé : {$total} course(s) déverrouillée(s)")
                        ->success()
                        ->send();
                }),

            Actions\Action::make('triggerEmergencyGlobal')
                ->label('Déclencher Mode Urgence Général')
                ->icon('heroicon-m-exclamation-triangle')
                ->color('danger')
                ->visible(fn () => in_array(auth()->user()?->role, ['super_admin', 'dg_national'], true))
                ->form([
                    Forms\Components\Textarea::make('message')
                        ->label('Message d\'alerte diffusé aux utilisateurs et livreurs')
                        ->required()
                        ->string()
                        ->maxLength(255)
                        ->default('En raison d\'un cas de force majeure, le service de livraison est momentanément interrompu.'),
                ])
                ->modalHeading('Arrêt d\'urgence de sécurité - Toutes les villes')
                ->modalSubmitActionLabel('Enclencher l\'Urgence')
                ->action(function (array $data): void {
                    /** @var User|null $user */
                    $user = auth()->user();
                    if (! $user instanceof User || ! in_array($user->role, ['super_admin', 'dg_national'], true)) {
                        abort(403, 'L\'activation du mode urgence général est réservée au Super Admin ou DG National.');
                    }

                    $cities = City::all();
                    $activityLogService = app(ActivityLogService::class);

                    foreach ($cities as $city) {
                        $oldState = (bool) $city->emergency_mode_active;
                        $city->update([
                            'emergency_mode_active' => true,
                            'emergency_mode_activated_at' => now(),
                            'emergency_mode_message' => $data['message'],
                        ]);

                        $activityLogService->log(
                            userId: $user->id,
                            action: 'emergency_mode_activated',
                            entityType: 'city',
                            entityId: $city->id,
                            properties: [
                                'user_id' => $user->id,
                                'user_name' => $user->name,
                                'user_role' => $user->role,
                                'city_id' => $city->id,
                                'city_name' => $city->name,
                                'ancien_etat' => $oldState,
                                'nouvel_etat' => true,
                                'message' => $data['message'],
                            ],
                            severity: 'critical',
                        );
                    }

                    Notification::make()
                        ->title('Mode Urgence activé sur l\'ensemble des zones')
                        ->danger()
                        ->send();
                }),

            Actions\Action::make('clearEmergencyGlobal')
                ->label('Lever le Mode Urgence Général')
                ->icon('heroicon-m-shield-check')
                ->color('success')
                ->visible(fn () => auth()->user()?->role === 'super_admin')
                ->requiresConfirmation()
                ->modalHeading('Reprise normale des activités')
                ->modalDescription('Cette action désactive le mode urgence sur toutes les villes et autorise la reprise des commandes.')
                ->modalSubmitActionLabel('Rétablir le service')
                ->action(function (): void {
                    /** @var User|null $user */
                    $user = auth()->user();
                    if (! $user instanceof User || $user->role !== 'super_admin') {
                        abort(403, 'La désactivation du mode urgence général est réservée au Super Admin seul.');
                    }

                    $cities = City::all();
                    $activityLogService = app(ActivityLogService::class);

                    foreach ($cities as $city) {
                        $oldState = (bool) $city->emergency_mode_active;
                        $city->update([
                            'emergency_mode_active' => false,
                            'emergency_mode_activated_at' => null,
                            'emergency_mode_message' => null,
                        ]);

                        $activityLogService->log(
                            userId: $user->id,
                            action: 'emergency_mode_deactivated',
                            entityType: 'city',
                            entityId: $city->id,
                            properties: [
                                'user_id' => $user->id,
                                'user_name' => $user->name,
                                'user_role' => $user->role,
                                'city_id' => $city->id,
                                'city_name' => $city->name,
                                'ancien_etat' => $oldState,
                                'nouvel_etat' => false,
                                'message' => null,
                            ],
                            severity: 'critical',
                        );
                    }

                    Notification::make()
                        ->title('Toutes les zones sont revenues en fonctionnement normal')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(City::query())
            ->heading('Pilotage Rapide par Ville & Zone')
            ->description('Activez ou désactivez le Mode Pluie et le Mode Urgence zone par zone avec prise d\'effet immédiate.')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Ville / Zone')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Opérationnelle')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\TextColumn::make('rain_mode_active')
                    ->label('Mode Pluie (+20%)')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'warning' : 'gray')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Actif (+20%)' : 'Désactivé')
                    ->icon(fn (bool $state): string => $state ? 'heroicon-m-cloud' : 'heroicon-m-sun'),

                Tables\Columns\TextColumn::make('emergency_mode_active')
                    ->label('Mode Urgence')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'danger' : 'success')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Urgence Enclenchée' : 'Normal')
                    ->icon(fn (bool $state): string => $state ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-shield-check'),

                Tables\Columns\TextColumn::make('emergency_mode_message')
                    ->label('Alerte Urgence en cours')
                    ->placeholder('Aucune alerte')
                    ->limit(40),
            ])
            ->actions([
                Tables\Actions\Action::make('toggleRain')
                    ->label(fn (City $record): string => $record->rain_mode_active ? 'Couper Pluie' : 'Activer Pluie')
                    ->icon(fn (City $record): string => $record->rain_mode_active ? 'heroicon-m-sun' : 'heroicon-m-cloud')
                    ->color(fn (City $record): string => $record->rain_mode_active ? 'gray' : 'warning')
                    ->button()
                    ->action(function (City $record): void {
                        $newStatus = ! $record->rain_mode_active;
                        $record->update([
                            'rain_mode_active' => $newStatus,
                            'rain_mode_activated_at' => $newStatus ? now() : null,
                        ]);
                        Notification::make()
                            ->title("Mode Pluie {$record->name} : ".($newStatus ? 'Activé (+20%)' : 'Désactivé'))
                            ->color($newStatus ? 'warning' : 'success')
                            ->send();
                    }),

                Tables\Actions\Action::make('toggleEmergency')
                    ->label(fn (City $record): string => $record->emergency_mode_active ? 'Lever Urgence' : 'Déclencher Urgence')
                    ->icon(fn (City $record): string => $record->emergency_mode_active ? 'heroicon-m-shield-check' : 'heroicon-m-exclamation-triangle')
                    ->color(fn (City $record): string => $record->emergency_mode_active ? 'success' : 'danger')
                    ->button()
                    ->visible(fn (City $record): bool => $record->emergency_mode_active
                        ? auth()->user()?->role === 'super_admin'
                        : in_array(auth()->user()?->role, ['super_admin', 'dg_national'], true))
                    ->form(fn (City $record): array => $record->emergency_mode_active ? [] : [
                        Forms\Components\Textarea::make('message')
                            ->label('Message d\'alerte diffusé aux clients et livreurs de cette ville')
                            ->default('En raison d\'intempéries ou de circonstances exceptionnelles, les livraisons sont momentanément suspendues.')
                            ->string()
                            ->maxLength(255)
                            ->required(),
                    ])
                    ->modalHeading(fn (City $record): string => $record->emergency_mode_active ? "Lever l'urgence sur {$record->name}" : "Déclencher l'urgence sur {$record->name}")
                    ->modalSubmitActionLabel(fn (City $record): string => $record->emergency_mode_active ? 'Confirmer la réouverture' : 'Confirmer l\'arrêt d\'urgence')
                    ->action(function (City $record, array $data): void {
                        /** @var User|null $user */
                        $user = auth()->user();
                        if (! $user instanceof User) {
                            abort(403);
                        }

                        $newStatus = ! $record->emergency_mode_active;

                        if ($newStatus) {
                            if (! in_array($user->role, ['super_admin', 'dg_national'], true)) {
                                abort(403, 'L\'activation du mode urgence est réservée au Super Admin ou DG National.');
                            }
                        } else {
                            if ($user->role !== 'super_admin') {
                                abort(403, 'La désactivation du mode urgence est réservée au Super Admin seul.');
                            }
                        }

                        $oldState = (bool) $record->emergency_mode_active;
                        $record->update([
                            'emergency_mode_active' => $newStatus,
                            'emergency_mode_activated_at' => $newStatus ? now() : null,
                            'emergency_mode_message' => $newStatus ? ($data['message'] ?? 'Service temporairement interrompu') : null,
                        ]);

                        app(ActivityLogService::class)->log(
                            userId: $user->id,
                            action: $newStatus ? 'emergency_mode_activated' : 'emergency_mode_deactivated',
                            entityType: 'city',
                            entityId: $record->id,
                            properties: [
                                'user_id' => $user->id,
                                'user_name' => $user->name,
                                'user_role' => $user->role,
                                'city_id' => $record->id,
                                'city_name' => $record->name,
                                'ancien_etat' => $oldState,
                                'nouvel_etat' => $newStatus,
                                'message' => $newStatus ? ($data['message'] ?? null) : null,
                            ],
                            severity: 'critical',
                        );

                        Notification::make()
                            ->title("Mode Urgence {$record->name} : ".($newStatus ? 'Enclenché' : 'Levé'))
                            ->color($newStatus ? 'danger' : 'success')
                            ->send();
                    }),
            ])
            ->paginated(false);
    }
}
