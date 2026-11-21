<?php

namespace App\Filament\Resources\ParcelResource\Pages;

use App\Filament\Resources\ParcelResource;
use App\Filament\Support\FilamentCityScope;
use App\Models\Courier;
use App\Models\Parcel;
use App\Models\User;
use App\Modules\GoShop\Services\AssignmentService;
use App\Services\DeliverySettlementService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditParcel extends EditRecord
{
    protected static string $resource = ParcelResource::class;

    protected ?int $previousCourierId = null;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Parcel $record */
        $record = $this->getRecord();
        $this->previousCourierId = $record->courier_id !== null ? (int) $record->courier_id : null;
        $data['declared_value'] = (int) ($data['declared_value'] ?? 0);
        $data['insurance_amount'] = (int) ($data['insurance_amount'] ?? 0);

        return FilamentCityScope::enforceCityOnSave($data, $record);
    }

    protected function afterSave(): void
    {
        /** @var Parcel $record */
        $record = $this->getRecord();

        $newCourierId = $record->courier_id !== null ? (int) $record->courier_id : null;
        if ($newCourierId !== null && $newCourierId !== $this->previousCourierId) {
            $courier = Courier::find($newCourierId);
            $admin = auth()->user();
            if ($courier && $admin instanceof User) {
                app(AssignmentService::class)->assignerManuellementParAdmin($record, $courier, $admin);
            }
        }

        if ($record->status === Parcel::STATUS_DELIVERED) {
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
