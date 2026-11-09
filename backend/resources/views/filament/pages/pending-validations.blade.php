<x-filament-panels::page>
    <div class="space-y-6">
        <!-- Tab selector pills -->
        <div class="flex flex-wrap items-center gap-2 border-b border-gray-200 dark:border-gray-800 pb-3">
            <button
                type="button"
                wire:click="setTab('all')"
                class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg transition {{ $activeTab === 'all' ? 'bg-amber-500 text-white shadow-sm' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700' }}"
            >
                <x-heroicon-m-squares-2x2 class="w-4 h-4" />
                <span>Tous les dossiers</span>
                <span class="inline-flex items-center justify-center px-2 py-0.5 text-xs font-bold rounded-full {{ $activeTab === 'all' ? 'bg-white/20 text-white' : 'bg-gray-200 text-gray-800 dark:bg-gray-700 dark:text-gray-200' }}">
                    {{ $this->pendingCouriersCount + $this->pendingMerchantsCount }}
                </span>
            </button>

            <button
                type="button"
                wire:click="setTab('couriers')"
                class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg transition {{ $activeTab === 'couriers' ? 'bg-amber-500 text-white shadow-sm' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700' }}"
            >
                <x-heroicon-m-truck class="w-4 h-4" />
                <span>Livreurs Partenaires</span>
                <span class="inline-flex items-center justify-center px-2 py-0.5 text-xs font-bold rounded-full {{ $this->pendingCouriersCount > 0 ? 'bg-amber-600 text-white' : 'bg-gray-200 text-gray-800 dark:bg-gray-700 dark:text-gray-200' }}">
                    {{ $this->pendingCouriersCount }}
                </span>
            </button>

            <button
                type="button"
                wire:click="setTab('merchants')"
                class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg transition {{ $activeTab === 'merchants' ? 'bg-amber-500 text-white shadow-sm' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700' }}"
            >
                <x-heroicon-m-building-storefront class="w-4 h-4" />
                <span>Commerces Partenaires</span>
                <span class="inline-flex items-center justify-center px-2 py-0.5 text-xs font-bold rounded-full {{ $this->pendingMerchantsCount > 0 ? 'bg-amber-600 text-white' : 'bg-gray-200 text-gray-800 dark:bg-gray-700 dark:text-gray-200' }}">
                    {{ $this->pendingMerchantsCount }}
                </span>
            </button>
        </div>

        <!-- Tables Content -->
        @if ($activeTab === 'all' || $activeTab === 'couriers')
            <div wire:key="widget-couriers" class="space-y-4">
                @livewire(\App\Filament\Pages\Widgets\PendingCouriersWidget::class)
            </div>
        @endif

        @if ($activeTab === 'all' || $activeTab === 'merchants')
            <div wire:key="widget-merchants" class="space-y-4">
                @livewire(\App\Filament\Pages\Widgets\PendingMerchantsWidget::class)
            </div>
        @endif
    </div>
</x-filament-panels::page>
