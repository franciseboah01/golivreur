<x-filament-panels::page>
    <div class="max-w-lg mx-auto bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 space-y-5">
        <div>
            <h2 class="text-lg font-bold text-gray-900 dark:text-white">
                Authentification à Deux Facteurs (2FA)
            </h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                Pour protéger l'accès au panneau d'administration GoLivreur, saisissez le code à 6 chiffres reçu sur votre téléphone ou email administrateur.
            </p>
        </div>

        @if ($statusMessage)
            <div class="rounded-lg bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-700 p-3 text-sm text-amber-900 dark:text-amber-100">
                {{ $statusMessage }}
            </div>
        @endif

        @if ($errorMessage)
            <div class="rounded-lg bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-700 p-3 text-sm text-red-700 dark:text-red-200">
                {{ $errorMessage }}
            </div>
        @endif

        <form wire:submit.prevent="verifierCode" class="space-y-4">
            <div>
                <label for="two_factor_code" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                    Code de vérification (6 chiffres)
                </label>
                <input
                    id="two_factor_code"
                    type="text"
                    inputmode="numeric"
                    maxlength="6"
                    wire:model.defer="code"
                    placeholder="000000"
                    class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-center text-2xl tracking-widest font-bold"
                    required
                />
            </div>

            <div class="flex items-center justify-between gap-3">
                <x-filament::button type="button" color="gray" wire:click="envoyerCode">
                    Renvoyer un code
                </x-filament::button>

                <x-filament::button type="submit" color="primary">
                    Valider la connexion 2FA
                </x-filament::button>
            </div>
        </form>
    </div>
</x-filament-panels::page>
