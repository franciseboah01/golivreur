<?php

namespace App\Filament\Resources\WalletTransactionResource\Pages;

use App\Filament\Pages\CashWalletOperationsPage;
use App\Filament\Resources\WalletTransactionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListWalletTransactions extends ListRecords
{
    protected static string $resource = WalletTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('cashWalletOperations')
                ->label('Caisse Espèces (Créditer / Débiter)')
                ->icon('heroicon-o-currency-dollar')
                ->color('success')
                ->visible(fn (): bool => CashWalletOperationsPage::canAccess())
                ->url(fn (): string => CashWalletOperationsPage::getUrl()),
        ];
    }
}
