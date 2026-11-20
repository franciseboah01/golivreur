<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Bandeau d'explication + lien rapide vers l'historique complet --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h2 class="text-base font-bold text-gray-900 dark:text-white">
                    💵 Guichet Caisse Espèces — Dépôt &amp; Retrait Portefeuille
                </h2>
                <p class="text-xs text-gray-600 dark:text-gray-400 mt-1">
                    Encaissez ou remettez l'argent liquide, saisissez le numéro de téléphone du <strong>Commerce</strong> ou du <strong>Livreur</strong>, puis validez. Le solde et l'historique se mettent à jour <strong>automatiquement</strong> dans <strong>GL Business</strong> et <strong>GL Driver</strong>.
                </p>
            </div>
            <x-filament::button
                tag="a"
                :href="$this->transactionsListUrl"
                color="gray"
                icon="heroicon-o-list-bullet"
            >
                Voir toutes les transactions
            </x-filament::button>
        </div>

        {{-- 1. Recherche du Commerce ou Livreur par numéro --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
            <form wire:submit.prevent="rechercherPartenaire" class="flex flex-col sm:flex-row gap-3 items-end">
                <div class="flex-1 w-full">
                    <label for="partner_phone" class="block text-sm font-semibold text-gray-800 dark:text-gray-200">
                        Numéro de téléphone du Commerce ou du Livreur
                    </label>
                    <input
                        id="partner_phone"
                        type="text"
                        wire:model.live.debounce.350ms="searchPhone"
                        placeholder="Ex : 0701020304 ou +225 07 01 02 03 04"
                        class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-base font-medium"
                    />
                </div>
                <x-filament::button type="submit" color="primary" icon="heroicon-o-magnifying-glass">
                    Rechercher le compte
                </x-filament::button>
            </form>

            @if ($lookupNotice)
                <div class="mt-3 rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 px-3 py-2 text-sm text-amber-800 dark:text-amber-200 font-medium">
                    ⚠️ {{ $lookupNotice }}
                </div>
            @endif
        </div>

        {{-- 2. Reçu immédiat après validation d'une opération --}}
        @if ($lastReceipt)
            <div class="rounded-xl border-2 {{ $lastReceipt['direction'] === 'credit' ? 'border-emerald-400 bg-emerald-50 dark:bg-emerald-950/30' : 'border-rose-400 bg-rose-50 dark:bg-rose-950/30' }} p-5 space-y-2">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-base font-bold {{ $lastReceipt['direction'] === 'credit' ? 'text-emerald-900 dark:text-emerald-200' : 'text-rose-900 dark:text-rose-200' }}">
                        ✅ {{ $lastReceipt['direction'] === 'credit' ? 'Dépôt espèces crédité avec succès' : 'Retrait / Débit espèces validé avec succès' }}
                    </h3>
                    <span class="px-2.5 py-1 rounded-md text-xs font-mono font-bold bg-white/80 dark:bg-gray-900/70 text-gray-800 dark:text-gray-200">
                        Réf : {{ $lastReceipt['reference'] }}
                    </span>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-xs pt-2">
                    <div>
                        <span class="text-gray-500">Bénéficiaire</span>
                        <div class="font-bold text-gray-900 dark:text-white">
                            {{ $lastReceipt['owner_type'] === 'merchant' ? '🏪' : '🏍️' }} {{ $lastReceipt['owner_name'] }}
                        </div>
                        <div class="text-gray-600 dark:text-gray-400">{{ $lastReceipt['owner_phone'] }}</div>
                    </div>
                    <div>
                        <span class="text-gray-500">Montant opération</span>
                        <div class="text-sm font-extrabold {{ $lastReceipt['direction'] === 'credit' ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300' }}">
                            {{ $lastReceipt['direction'] === 'credit' ? '+' : '-' }}{{ number_format($lastReceipt['amount'], 0, ',', ' ') }} F CFA
                        </div>
                    </div>
                    <div>
                        <span class="text-gray-500">Évolution du solde</span>
                        <div class="font-bold text-gray-900 dark:text-white">
                            {{ number_format($lastReceipt['balance_before'], 0, ',', ' ') }} F → {{ number_format($lastReceipt['balance_after'], 0, ',', ' ') }} F CFA
                        </div>
                    </div>
                    <div>
                        <span class="text-gray-500">Opérateur &amp; Date</span>
                        <div class="font-semibold text-gray-900 dark:text-white">{{ $lastReceipt['operator'] }}</div>
                        <div class="text-gray-600 dark:text-gray-400">{{ $lastReceipt['date'] }}</div>
                    </div>
                </div>
            </div>
        @endif

        {{-- 3. Fiche partenaire + Formulaire Crédit / Débit Espèces --}}
        @if ($partnerData)
            @php
                $user = $partnerData['user'];
                $merchant = $partnerData['merchant'];
                $courier = $partnerData['courier'];
                $selectedBalance = $this->selectedBalance;
                $parsedAmount = $this->parsedAmount;
                $projectedBalance = $this->projectedBalance;
                $debitInsuffisant = $direction === 'debit' && $parsedAmount > 0 && $projectedBalance < 0;
            @endphp

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">
                {{-- Colonne gauche : Identité + Sélection du portefeuille (Commerce et/ou Livreur) --}}
                <div class="lg:col-span-5 bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5 space-y-4">
                    <div>
                        <div class="text-xs uppercase font-bold tracking-wider text-gray-500">Titulaire identifié</div>
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white mt-0.5">{{ $user['name'] ?? '' }}</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            📞 {{ $user['phone'] ?? '' }}
                            @if (! empty($user['city_name']))
                                • 📍 {{ $user['city_name'] }}
                            @endif
                        </p>
                    </div>

                    <div class="space-y-3 pt-2 border-t border-gray-100 dark:border-gray-800">
                        <div class="text-xs font-semibold text-gray-600 dark:text-gray-400">
                            Sélectionnez le portefeuille à mouvementer :
                        </div>

                        @if ($merchant)
                            <button
                                type="button"
                                wire:click="selectionnerPortefeuille('merchant')"
                                class="w-full text-left p-4 rounded-xl border-2 transition {{ $selectedOwnerType === 'merchant' ? 'border-blue-600 bg-blue-50 dark:bg-blue-950/40' : 'border-gray-200 dark:border-gray-700 hover:border-blue-300' }}"
                            >
                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-bold text-blue-900 dark:text-blue-200">
                                        🏪 Commerce : {{ $merchant['name'] }}
                                    </span>
                                    @if ($selectedOwnerType === 'merchant')
                                        <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-blue-600 text-white">Sélectionné</span>
                                    @endif
                                </div>
                                <div class="text-xs text-blue-700 dark:text-blue-300 mt-1">
                                    Ville : {{ $merchant['city_name'] }} • {{ $merchant['is_active'] ? 'Actif' : 'Inactif' }}
                                </div>
                                <div class="text-xl font-extrabold text-blue-950 dark:text-white mt-2">
                                    {{ number_format($merchant['balance'], 0, ',', ' ') }} F CFA
                                </div>
                            </button>
                        @endif

                        @if ($courier)
                            <button
                                type="button"
                                wire:click="selectionnerPortefeuille('courier')"
                                class="w-full text-left p-4 rounded-xl border-2 transition {{ $selectedOwnerType === 'courier' ? 'border-emerald-600 bg-emerald-50 dark:bg-emerald-950/40' : 'border-gray-200 dark:border-gray-700 hover:border-emerald-300' }}"
                            >
                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-bold text-emerald-900 dark:text-emerald-200">
                                        🏍️ Livreur : {{ $courier['name'] }}
                                    </span>
                                    @if ($selectedOwnerType === 'courier')
                                        <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-emerald-600 text-white">Sélectionné</span>
                                    @endif
                                </div>
                                <div class="text-xs text-emerald-700 dark:text-emerald-300 mt-1">
                                    {{ ucfirst((string) $courier['vehicle_type']) }} ({{ $courier['vehicle_plate'] }}) • {{ $courier['city_name'] }}
                                </div>
                                <div class="text-xl font-extrabold text-emerald-950 dark:text-white mt-2">
                                    {{ number_format($courier['balance'], 0, ',', ' ') }} F CFA
                                </div>
                            </button>
                        @endif
                    </div>
                </div>

                {{-- Colonne droite : Saisie de l'opération Espèces --}}
                <div class="lg:col-span-7 bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                    <form wire:submit.prevent="executerOperationEspeces" class="space-y-5">
                        <div>
                            <label class="block text-sm font-semibold text-gray-800 dark:text-gray-200 mb-2">
                                Type d'opération espèces
                            </label>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <button
                                    type="button"
                                    wire:click="$set('direction', 'credit')"
                                    class="p-3.5 rounded-xl border-2 text-left transition {{ $direction === 'credit' ? 'border-emerald-600 bg-emerald-50 dark:bg-emerald-950/40' : 'border-gray-200 dark:border-gray-700' }}"
                                >
                                    <div class="font-bold text-emerald-800 dark:text-emerald-200">
                                        🟢 Créditer (Dépôt espèces)
                                    </div>
                                    <div class="text-xs text-gray-600 dark:text-gray-400 mt-0.5">
                                        Le gestionnaire reçoit l'argent liquide et recharge le solde sur l'app.
                                    </div>
                                </button>
                                <button
                                    type="button"
                                    wire:click="$set('direction', 'debit')"
                                    class="p-3.5 rounded-xl border-2 text-left transition {{ $direction === 'debit' ? 'border-rose-600 bg-rose-50 dark:bg-rose-950/40' : 'border-gray-200 dark:border-gray-700' }}"
                                >
                                    <div class="font-bold text-rose-800 dark:text-rose-200">
                                        🔴 Débiter (Retrait espèces)
                                    </div>
                                    <div class="text-xs text-gray-600 dark:text-gray-400 mt-0.5">
                                        Le gestionnaire remet l'argent liquide et déduit le solde sur l'app.
                                    </div>
                                </button>
                            </div>
                        </div>

                        <div>
                            <label for="cash_amount" class="block text-sm font-semibold text-gray-800 dark:text-gray-200">
                                Montant en espèces (F CFA)
                            </label>
                            <input
                                id="cash_amount"
                                type="number"
                                min="100"
                                step="50"
                                wire:model.live.debounce.200ms="amount"
                                placeholder="Ex : 10000"
                                class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-lg font-bold"
                                required
                            />
                            <div class="flex flex-wrap gap-2 mt-2">
                                @foreach ([1000, 2000, 5000, 10000, 25000, 50000] as $preset)
                                    <button
                                        type="button"
                                        wire:click="choisirMontantRapide({{ $preset }})"
                                        class="px-2.5 py-1 rounded-lg text-xs font-semibold border border-gray-300 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-800 text-gray-700 dark:text-gray-200"
                                    >
                                        {{ number_format($preset, 0, ',', ' ') }} F
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        <div>
                            <label for="cash_reason" class="block text-sm font-semibold text-gray-800 dark:text-gray-200">
                                Motif / Référence reçu caisse
                            </label>
                            <input
                                id="cash_reason"
                                type="text"
                                wire:model.defer="reason"
                                placeholder="{{ $direction === 'credit' ? 'Ex : Dépôt espèces au guichet — Reçu n°014' : 'Ex : Retrait gains en espèces — Reçu n°014' }}"
                                class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm"
                            />
                        </div>

                        {{-- Aperçu avant validation --}}
                        <div class="rounded-xl p-4 border {{ $debitInsuffisant ? 'bg-rose-50 dark:bg-rose-950/30 border-rose-300' : 'bg-gray-50 dark:bg-gray-800/60 border-gray-200 dark:border-gray-700' }}">
                            <div class="flex items-center justify-between text-xs text-gray-600 dark:text-gray-400">
                                <span>Portefeuille ciblé</span>
                                <span class="font-bold text-gray-900 dark:text-white">
                                    {{ $selectedOwnerType === 'merchant' ? '🏪 Commerce (GL Business)' : '🏍️ Livreur (GL Driver)' }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between text-xs text-gray-600 dark:text-gray-400 mt-1.5">
                                <span>Solde actuel</span>
                                <span class="font-semibold">{{ number_format($selectedBalance, 0, ',', ' ') }} F CFA</span>
                            </div>
                            <div class="flex items-center justify-between text-sm font-bold mt-2 pt-2 border-t border-gray-200 dark:border-gray-700">
                                <span>Nouveau solde après validation</span>
                                <span class="{{ $debitInsuffisant ? 'text-rose-600' : ($direction === 'credit' ? 'text-emerald-700 dark:text-emerald-300' : 'text-amber-700 dark:text-amber-300') }}">
                                    {{ number_format($projectedBalance, 0, ',', ' ') }} F CFA
                                </span>
                            </div>
                            @if ($debitInsuffisant)
                                <p class="text-xs font-semibold text-rose-600 mt-1.5">
                                    ⚠️ Solde insuffisant : le solde d'un portefeuille ne peut pas devenir négatif.
                                </p>
                            @endif
                        </div>

                        <div class="flex justify-end">
                            <x-filament::button
                                type="submit"
                                :color="$direction === 'credit' ? 'success' : 'danger'"
                                :disabled="$debitInsuffisant || $parsedAmount <= 0"
                                icon="{{ $direction === 'credit' ? 'heroicon-o-plus-circle' : 'heroicon-o-minus-circle' }}"
                                wire:confirm="{{ $direction === 'credit' ? 'Confirmez-vous avoir encaissé l\'argent liquide et vouloir CRÉDITER ce portefeuille ?' : 'Confirmez-vous la remise des espèces et vouloir DÉBITER ce portefeuille ?' }}"
                            >
                                {{ $direction === 'credit' ? 'Valider le dépôt espèces (Créditer)' : 'Valider le retrait espèces (Débiter)' }}
                            </x-filament::button>
                        </div>
                    </form>
                </div>
            </div>
        @endif

        {{-- 4. Historique récent des dépôts & retraits espèces --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5 space-y-3">
            <h3 class="text-sm font-bold text-gray-900 dark:text-white">
                🧾 Dernières opérations espèces (Dépôts &amp; Retraits)
            </h3>
            <div class="overflow-x-auto text-xs">
                <table class="w-full text-left">
                    <thead class="text-gray-500 border-b border-gray-200 dark:border-gray-700">
                        <tr>
                            <th class="py-2 pr-3">Date</th>
                            <th class="py-2 pr-3">Référence</th>
                            <th class="py-2 pr-3">Partenaire</th>
                            <th class="py-2 pr-3">Téléphone</th>
                            <th class="py-2 pr-3">Opération</th>
                            <th class="py-2 pr-3">Montant</th>
                            <th class="py-2 pr-3">Nouveau Solde</th>
                            <th class="py-2 pr-3">Motif</th>
                            <th class="py-2">Gestionnaire</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($this->recentCashOperations as $op)
                            <tr>
                                <td class="py-2 pr-3 whitespace-nowrap">{{ $op['date'] }}</td>
                                <td class="py-2 pr-3 font-mono whitespace-nowrap">{{ $op['reference'] }}</td>
                                <td class="py-2 pr-3 font-semibold">{{ $op['partner_label'] }}</td>
                                <td class="py-2 pr-3 whitespace-nowrap">{{ $op['partner_phone'] }}</td>
                                <td class="py-2 pr-3">
                                    <span class="px-2 py-0.5 rounded-full font-bold {{ $op['type'] === 'deposit' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' : 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200' }}">
                                        {{ $op['type'] === 'deposit' ? 'Dépôt espèces' : 'Retrait espèces' }}
                                    </span>
                                </td>
                                <td class="py-2 pr-3 font-bold whitespace-nowrap {{ $op['amount'] >= 0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-rose-700 dark:text-rose-300' }}">
                                    {{ $op['amount'] >= 0 ? '+' : '' }}{{ number_format($op['amount'], 0, ',', ' ') }} F
                                </td>
                                <td class="py-2 pr-3 font-semibold whitespace-nowrap">
                                    {{ number_format($op['balance_after'], 0, ',', ' ') }} F
                                </td>
                                <td class="py-2 pr-3 max-w-xs truncate" title="{{ $op['description'] }}">
                                    {{ $op['description'] }}
                                </td>
                                <td class="py-2 whitespace-nowrap">{{ $op['operator'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="py-4 text-center text-gray-500">
                                    Aucune opération espèces enregistrée pour le moment.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
