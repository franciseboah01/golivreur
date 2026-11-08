<?php

namespace App\Filament\Resources\RoleHabilitationResource\Pages;

use App\Filament\Resources\RoleHabilitationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRoleHabilitation extends CreateRecord
{
    protected static string $resource = RoleHabilitationResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

