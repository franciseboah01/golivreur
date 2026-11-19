<x-filament-panels::page>
    <div class="space-y-6">
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Portée des bannières</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Choisissez « Global (toutes les villes) » ou personnalisez les bannières pour une ville précise.
                </p>
            </div>
            <select
                wire:model.live="selectedCityId"
                class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm"
            >
                <option value="">🌍 Global (toutes les villes)</option>
                @foreach ($this->citiesOptions as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <form wire:submit.prevent="save" class="space-y-6">
            {{ $this->form }}

            <div class="flex justify-end">
                <x-filament::button type="submit" color="primary" icon="heroicon-o-check">
                    Enregistrer les bannières
                </x-filament::button>
            </div>
        </form>
    </div>
</x-filament-panels::page>
