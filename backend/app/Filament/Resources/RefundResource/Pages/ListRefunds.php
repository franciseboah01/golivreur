<?php

namespace App\Filament\Resources\RefundResource\Pages;

use App\Filament\Components\SearchableSelect;
use App\Filament\Resources\RefundResource;
use App\Filament\Support\FilamentCityScope;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\User;
use App\Services\RefundService;
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
                ->modalDescription('Contrôle strict des plafonds depuis settings (CDC Ch. 8.5) sans mouvement wallet livreur.')
                ->form([
                    SearchableSelect::make('contexte')
                        ->label('Type d\'opération')
                        ->options(SearchableSelect::sortedOptions([
                            'order' => 'Commande GoShop (Repas / Courses)',
                            'parcel' => 'Expédition GoColis (Colis perdu / endommagé)',
                        ]))
                        ->required()
                        ->reactive(),

                    SearchableSelect::make('order_id')
                        ->label('Commande GoShop concernée')
                        ->options(fn () => SearchableSelect::sortedOptions(
                            FilamentCityScope::scopeByCity(Order::with('client'))
                                ->latest()
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(
                                    fn (Order $o) => [$o->id => "#{$o->id} - {$o->total_client} F CFA ({$o->client?->name})"]
                                )->toArray()
                        ))
                        ->visible(fn (Forms\Get $get) => $get('contexte') === 'order')
                        ->required(fn (Forms\Get $get) => $get('contexte') === 'order'),

                    SearchableSelect::make('parcel_id')
                        ->label('Colis GoColis concerné')
                        ->options(fn () => SearchableSelect::sortedOptions(
                            FilamentCityScope::scopeByCity(Parcel::query())
                                ->latest()
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(
                                    fn (Parcel $p) => [$p->id => "#{$p->id} - Déclaré {$p->declared_value} F CFA"]
                                )->toArray()
                        ))
                        ->visible(fn (Forms\Get $get) => $get('contexte') === 'parcel')
                        ->required(fn (Forms\Get $get) => $get('contexte') === 'parcel'),

                    Forms\Components\TextInput::make('montant')
                        ->label('Montant du remboursement (F CFA)')
                        ->numeric()
                        ->prefix('F CFA')
                        ->required()
                        ->helperText(function (): string {
                            /** @var User|null $user */
                            $user = auth()->user();
                            $role = (string) ($user->role ?? 'city_agent');
                            $monPlafond = app(RefundService::class)->plafondPourRole($role, $user?->city_id);
                            $labelPlafond = $monPlafond === PHP_INT_MAX ? 'illimité' : "{$monPlafond} F CFA";

                            return "Votre rôle ({$role}) vous autorise un plafond maximal de {$labelPlafond}.";
                        }),

                    Forms\Components\Textarea::make('motif')
                        ->label('Motif circonstancié de la décision')
                        ->required()
                        ->rows(3),
                ])
                ->action(function (array $data): void {
                    /** @var User $user */
                    $user = auth()->user();
                    $refundService = app(RefundService::class);
                    $montant = (int) round((float) $data['montant']);
                    $motif = (string) $data['motif'];

                    $order = null;
                    $parcel = null;

                    if ($data['contexte'] === 'order') {
                        /** @var Order $order */
                        $order = Order::findOrFail($data['order_id']);
                        if (! FilamentCityScope::canAccessCityId((int) $order->city_id, $user)) {
                            Notification::make()
                                ->title('Remboursement refusé')
                                ->body('Cette commande n\'appartient pas à votre ville.')
                                ->danger()
                                ->send();

                            return;
                        }
                        $desc = "Remboursement commande #{$order->id} : {$motif}";
                    } else {
                        /** @var Parcel $parcel */
                        $parcel = Parcel::findOrFail($data['parcel_id']);
                        if (! FilamentCityScope::canAccessCityId((int) $parcel->city_id, $user)) {
                            Notification::make()
                                ->title('Remboursement refusé')
                                ->body('Ce colis n\'appartient pas à votre ville.')
                                ->danger()
                                ->send();

                            return;
                        }
                        $desc = "Indemnisation colis #{$parcel->id} : {$motif}";
                    }

                    $res = $refundService->creerEtApprouverDirect(
                        order: $order,
                        parcel: $parcel,
                        montant: $montant,
                        raison: $desc,
                        admin: $user,
                    );

                    if (! $res['ok']) {
                        Notification::make()
                            ->title('Remboursement refusé')
                            ->body($res['message'] ?? 'Opération impossible.')
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Remboursement enregistré et approuvé')
                        ->body("Montant : {$montant} F CFA")
                        ->success()
                        ->send();
                }),
        ];
    }
}
