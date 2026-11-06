<?php

namespace App\Filament\Pages;

use App\Models\City;
use App\Models\Order;
use App\Models\Parcel;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;

class EmergencyCenter extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-bolt';

    protected static string $view = 'filament.pages.emergency-center';

    protected static ?string $navigationGroup = 'Autre';

    protected static ?string $navigationLabel = 'Déclencheurs d\'Urgences';

    protected static ?string $title = 'Centre d\'Urgences & Déclencheurs Rapides';

    protected static ?int $navigationSort = 1;

    public string $emergencyBroadcastMessage = 'En raison de circonstances exceptionnelles, les nouvelles commandes sont temporairement suspendues.';

    public function getCitiesProperty(): Collection
    {
        return City::orderBy('name')->get();
    }

    public function getStatsProperty(): array
    {
        return [
            'total_cities' => City::count(),
            'rain_active' => City::where('rain_mode_active', true)->count(),
            'emergency_active' => City::where('emergency_mode_active', true)->count(),
            'locked_orders' => Order::where('confirmation_locked', true)->count(),
            'locked_parcels' => Parcel::where('sender_code_locked', true)->orWhere('recipient_code_locked', true)->count(),
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
                ->form([
                    Forms\Components\Textarea::make('message')
                        ->label('Message d\'alerte diffusé aux utilisateurs et livreurs')
                        ->required()
                        ->default('En raison d\'un cas de force majeure, le service de livraison est momentanément interrompu.'),
                ])
                ->modalHeading('Arrêt d\'urgence de sécurité - Toutes les villes')
                ->modalSubmitActionLabel('Enclencher l\'Urgence')
                ->action(function (array $data): void {
                    City::query()->update([
                        'emergency_mode_active' => true,
                        'emergency_mode_activated_at' => now(),
                        'emergency_message' => $data['message'],
                    ]);

                    Notification::make()
                        ->title('Mode Urgence activé sur l\'ensemble des zones')
                        ->danger()
                        ->send();
                }),

            Actions\Action::make('clearEmergencyGlobal')
                ->label('Lever le Mode Urgence Général')
                ->icon('heroicon-m-shield-check')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Reprise normale des activités')
                ->modalDescription('Cette action désactive le mode urgence sur toutes les villes et autorise la reprise des commandes.')
                ->modalSubmitActionLabel('Rétablir le service')
                ->action(function (): void {
                    City::query()->update([
                        'emergency_mode_active' => false,
                        'emergency_mode_activated_at' => null,
                        'emergency_message' => null,
                    ]);

                    Notification::make()
                        ->title('Toutes les zones sont revenues en fonctionnement normal')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function toggleCityRain(int $cityId): void
    {
        $city = City::findOrFail($cityId);
        $newStatus = ! $city->rain_mode_active;

        $city->update([
            'rain_mode_active' => $newStatus,
            'rain_mode_activated_at' => $newStatus ? now() : null,
        ]);

        Notification::make()
            ->title("Mode Pluie {$city->name} : " . ($newStatus ? 'Activé (+20%)' : 'Désactivé'))
            ->color($newStatus ? 'warning' : 'success')
            ->send();
    }

    public function toggleCityEmergency(int $cityId): void
    {
        $city = City::findOrFail($cityId);
        $newStatus = ! $city->emergency_mode_active;

        $city->update([
            'emergency_mode_active' => $newStatus,
            'emergency_mode_activated_at' => $newStatus ? now() : null,
            'emergency_message' => $newStatus ? $this->emergencyBroadcastMessage : null,
        ]);

        Notification::make()
            ->title("Mode Urgence {$city->name} : " . ($newStatus ? 'Enclenché' : 'Levé'))
            ->color($newStatus ? 'danger' : 'success')
            ->send();
    }
}

