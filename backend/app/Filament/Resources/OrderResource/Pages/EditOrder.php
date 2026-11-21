<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Filament\Support\FilamentCityScope;
use App\Models\Courier;
use App\Models\Order;
use App\Models\User;
use App\Modules\GoShop\Services\AssignmentService;
use App\Services\DeliverySettlementService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected ?int $previousCourierId = null;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Order $record */
        $record = $this->getRecord();
        $this->previousCourierId = $record->courier_id !== null ? (int) $record->courier_id : null;

        return FilamentCityScope::enforceCityOnSave($data, $record);
    }

    protected function afterSave(): void
    {
        /** @var Order $record */
        $record = $this->getRecord();

        $newCourierId = $record->courier_id !== null ? (int) $record->courier_id : null;
        if ($newCourierId !== null && $newCourierId !== $this->previousCourierId) {
            $courier = Courier::find($newCourierId);
            $admin = auth()->user();
            if ($courier && $admin instanceof User) {
                app(AssignmentService::class)->assignerManuellementParAdmin($record, $courier, $admin);
            }
        }

        if ($record->status === Order::STATUS_DELIVERED) {
            app(DeliverySettlementService::class)->reglerLivraison($record);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
