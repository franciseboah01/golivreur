<?php

namespace App\Filament\Resources\RefundResource\Pages;

use App\Filament\Components\SearchableSelect;
use App\Filament\Resources\RefundResource;
use App\Models\{ActivityLog, Order, Parcel, User};
use App\Services\{SettingsService, WalletService};
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListRefunds extends ListRecords
{
    protected static string $resource = RefundResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('createRefund')
                ->label('Émettre un Remboursement')
                ->icon('heroicon-m-plus-circle')
                ->color('danger')
                ->modalHeading('Validation d\'un Remboursement ou d\'une Indemnisation')
                ->modalDescription('Contrôle strict des plafonds selon votre rôle (CDC Ch. 8.5 & 10).')
                ->form([
                    SearchableSelect::make('contexte')
                        ->label('Type d\'opération')
                        ->options(SearchableSelect::sortedOptions([
                            'order'  => 'Commande GoShop (Repas / Courses)',
                            'parcel' => 'Expédition GoColis (Colis endommagé / retard)',
                        ]))
                        ->required()
                        ->reactive(),

                    SearchableSelect::make('order_id')
                        ->label('Commande GoShop concernée')
                        ->options(fn () => SearchableSelect::sortedOptions(
                            Order::latest()->limit(50)->get()->mapWithKeys(
                                fn ($o) => [$o->id => "#{$o->order_number} - {$o->total} F CFA ({$o->client?->name})"]
                            )->toArray()
                        ))
                        ->visible(fn (Forms\Get $get) => $get('contexte') === 'order')
                        ->required(fn (Forms\Get $get) => $get('contexte') === 'order'),

                    SearchableSelect::make('parcel_id')
                        ->label('Colis GoColis concerné')
                        ->options(fn () => SearchableSelect::sortedOptions(
                            Parcel::latest()->limit(50)->get()->mapWithKeys(
                                fn ($p) => [$p->id => "#{$p->tracking_number} - Déclaré {$p->declared_value} F CFA"]
                            )->toArray()
                        ))
                        ->visible(fn (Forms\Get $get) => $get('contexte') === 'parcel')
                        ->required(fn (Forms\Get $get) => $get('contexte') === 'parcel'),

                    Forms\Components\TextInput::make('montant')
                        ->label('Montant du remboursement (F CFA)')
                        ->numeric()
                        ->prefix('F CFA')
                        ->required()
                        ->helperText(function () {
                            $user = auth()->user();
                            $settingsService = app(SettingsService::class);
                            $plafonds = $settingsService->get('remboursements')['plafonds'] ?? [
                                'city_support' => 2000,
                                'city_finance' => 10000,
                                'city_manager' => 10000,
                                'super_admin'  => 50000,
                            ];
                            $monPlafond = $plafonds[$user->role] ?? 0;
                            return "Votre rôle ({$user->role}) vous autorise un plafond maximal de {$monPlafond} F CFA.";
                        }),

                    Forms\Components\Textarea::make('motif')
                        ->label('Motif circonstancié de la décision')
                        ->required()
                        ->rows(3),
                ])
                ->action(function (array $data): void {
                    $user = auth()->user();
                    $settingsService = app(SettingsService::class);
                    $plafonds = $settingsService->get('remboursements')['plafonds'] ?? [
                        'city_support' => 2000,
                        'city_finance' => 10000,
                        'city_manager' => 10000,
                        'super_admin'  => 50000,
                    ];
                    $monPlafond = $plafonds[$user->role] ?? 0;
                    $montant = (float) $data['montant'];

                    if ($montant > $monPlafond) {
                        Notification::make()
                            ->title('Plafond d\'autorisation dépassé')
                            ->body("Vous ne pouvez pas valider un montant supérieur à {$monPlafond} F CFA pour votre rôle.")
                            ->danger()
                            ->send();
                        return;
                    }

                    $walletService = app(WalletService::class);

                    // Traitement selon contexte
                    if ($data['contexte'] === 'order') {
                        $order = Order::findOrFail($data['order_id']);
                        $ownerType = 'courier';
                        $ownerId = $order->courier_id ?? $order->client_id;
                        $desc = "Remboursement commande #{$order->order_number} : " . $data['motif'];
                    } else {
                        $parcel = Parcel::findOrFail($data['parcel_id']);
                        $ownerType = 'courier';
                        $ownerId = $parcel->courier_id ?? $parcel->sender_id;
                        $desc = "Indemnisation colis #{$parcel->tracking_number} : " . $data['motif'];
                        $parcel->update(['status' => 'refunded']);
                    }

                    $walletService->effectuerRemboursement(
                        ownerType: $ownerType,
                        ownerId: (int) $ownerId,
                        montant: $montant,
                        description: $desc,
                        adminId: $user->id,
                    );

                    Notification::make()
                        ->title('Remboursement validé et crédité avec succès')
                        ->body("Montant : {$montant} F CFA")
                        ->success()
                        ->send();
                }),
        ];
    }
}

