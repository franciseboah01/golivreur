<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Barre de recherche Screen Pop --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
            <form wire:submit.prevent="rechercherAppelant" class="flex flex-col sm:flex-row gap-3 items-end">
                <div class="flex-1 w-full">
                    <label for="caller_phone" class="block text-sm font-semibold text-gray-800 dark:text-gray-200">
                        Numéro de téléphone de l'appelant (Screen Pop instantané)
                    </label>
                    <input
                        id="caller_phone"
                        type="text"
                        wire:model.live.debounce.350ms="searchPhone"
                        placeholder="Ex : 0701234567 ou +225 07 01 23 45 67"
                        class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-base font-medium"
                    />
                </div>
                <x-filament::button type="submit" color="primary" icon="heroicon-o-magnifying-glass">
                    Afficher la fiche
                </x-filament::button>
            </form>

            @if ($lookupNotice)
                <p class="mt-3 text-sm text-amber-700 dark:text-amber-300 font-medium">{{ $lookupNotice }}</p>
            @endif
        </div>

        @if ($callerData)
            @php
                $user = $callerData['user'];
                $roles = $callerData['roles'];
                $identityVerified = (bool) $callerData['identity_verified'];
            @endphp

            {{-- 1. Profil + Rôles + Portefeuilles isolés --}}
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
                <div class="lg:col-span-2 bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5 space-y-4">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="text-lg font-bold text-gray-900 dark:text-white">{{ $user->name }}</h3>
                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                📞 {{ $user->phone }} @if($user->email) • ✉️ {{ $user->email }} @endif
                            </p>
                            <p class="text-xs text-gray-500 mt-1">
                                Ville : <strong>{{ $user->city?->name ?? 'Non définie' }}</strong>
                                • Statut : <strong>{{ $user->is_active ? 'Actif' : 'Désactivé' }}</strong>
                                @if ($callerData['must_change_password'])
                                    • <span class="text-amber-600 font-semibold">Changement mot de passe obligatoire</span>
                                @endif
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-1.5 justify-end">
                            @foreach ($roles as $role)
                                <span class="px-2.5 py-1 text-xs font-bold rounded-full bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-200 uppercase">
                                    {{ $role }}
                                </span>
                            @endforeach
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2 border-t border-gray-100 dark:border-gray-800 text-sm">
                        @if ($user->merchant)
                            <div class="p-3 rounded-lg bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-800">
                                <div class="font-semibold text-blue-900 dark:text-blue-200">🏪 Commerce : {{ $user->merchant->name }}</div>
                                <div class="text-xs text-blue-700 dark:text-blue-300 mt-0.5">
                                    Wallet Commerçant (isolé) : <strong>{{ number_format($callerData['merchant_wallet_balance'], 0, ',', ' ') }} F CFA</strong>
                                </div>
                            </div>
                        @endif

                        @if ($user->courier)
                            <div class="p-3 rounded-lg bg-green-50 dark:bg-green-950/30 border border-green-200 dark:border-green-800">
                                <div class="font-semibold text-green-900 dark:text-green-200">🏍️ Livreur : {{ $user->courier->vehicle_plate ?? 'Sans plaque' }}</div>
                                <div class="text-xs text-green-700 dark:text-green-300 mt-0.5">
                                    Wallet Livreur (isolé) : <strong>{{ number_format($callerData['courier_wallet_balance'], 0, ',', ' ') }} F CFA</strong>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- 2. Vérification d'identité stricte hors bande + Reset sécurisé --}}
                <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5 space-y-4">
                    <h4 class="text-sm font-bold text-gray-900 dark:text-white">
                        🔐 Vérification d'identité & Réinitialisation
                    </h4>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Vérification hors bande obligatoire par OTP (SMS/Email). Aucune question de sécurité autorisée. Le mot de passe temporaire n'est jamais affiché au conseiller.
                    </p>

                    @if (! $identityVerified)
                        <x-filament::button wire:click="envoyerOtpVerification" color="warning" class="w-full">
                            1. Envoyer un code OTP hors bande
                        </x-filament::button>

                        <form wire:submit.prevent="validerOtpVerification" class="space-y-2 pt-2 border-t border-gray-100 dark:border-gray-800">
                            <input
                                type="text"
                                inputmode="numeric"
                                maxlength="6"
                                wire:model.defer="otpCode"
                                placeholder="Code 6 chiffres dicté par le client"
                                class="block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-center font-bold tracking-widest"
                            />
                            <x-filament::button type="submit" color="success" class="w-full">
                                2. Vérifier l'identité
                            </x-filament::button>
                        </form>
                    @else
                        <div class="rounded-lg bg-green-50 dark:bg-green-900/30 border border-green-300 dark:border-green-700 p-3 text-xs text-green-800 dark:text-green-200 font-semibold">
                            ✅ Identité vérifiée hors bande — Actions déverrouillées (usage unique).
                        </div>

                        <div class="space-y-2">
                            <x-filament::button
                                wire:click="declencherResetMotDePasse"
                                wire:confirm="Confirmer l'envoi d'un mot de passe temporaire par SMS/Email à l'utilisateur ? Le mot de passe ne sera pas affiché ici."
                                color="danger"
                                class="w-full"
                            >
                                Réinitialiser le mot de passe (envoi direct)
                            </x-filament::button>

                            @if (! empty(array_intersect(['merchant', 'driver', 'courier'], $callerData['user']['roles'] ?? [])))
                                <x-filament::button
                                    wire:click="declencherResetPin"
                                    wire:confirm="Confirmer la réinitialisation du code PIN rapide (Business / Driver) et la révocation des sessions actives ?"
                                    color="warning"
                                    class="w-full"
                                >
                                    Réinitialiser le code PIN rapide (Business / Driver)
                                </x-filament::button>
                            @endif
                        </div>
                    @endif
                </div>
            </div>

            {{-- 3. Tickets de support & Incidents ouverts --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5 space-y-3">
                    <h4 class="text-sm font-bold text-gray-900 dark:text-white">
                        🎫 Tickets & Incidents ouverts ({{ count($callerData['open_tickets']) }})
                    </h4>
                    @forelse ($callerData['open_tickets'] as $ticket)
                        <div class="p-3 rounded-lg bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-800 text-xs flex justify-between items-center">
                            <div>
                                <span class="font-bold text-red-900 dark:text-red-200">{{ $ticket['reference'] }}</span>
                                — {{ $ticket['label'] }}
                            </div>
                            <span class="uppercase font-semibold text-red-700 dark:text-red-300">{{ $ticket['status'] }}</span>
                        </div>
                    @empty
                        <p class="text-xs text-gray-500">Aucun ticket ni incident ouvert pour cet utilisateur.</p>
                    @endforelse

                    <form wire:submit.prevent="ouvrirTicketSupport" class="pt-3 border-t border-gray-100 dark:border-gray-800 space-y-2">
                        <p class="text-xs font-semibold text-gray-700 dark:text-gray-300">Consigner un nouveau ticket d'appel :</p>
                        <input
                            type="text"
                            wire:model.defer="newTicketTitle"
                            placeholder="Objet du ticket"
                            class="block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-xs"
                        />
                        <textarea
                            wire:model.defer="newTicketBody"
                            rows="2"
                            placeholder="Résumé de l'appel (ne jamais saisir de mot de passe ou code secret)"
                            class="block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-xs"
                        ></textarea>
                        <x-filament::button type="submit" size="sm" color="gray">
                            Enregistrer le ticket
                        </x-filament::button>
                    </form>
                </div>

                {{-- 4. Historique Commandes GoShop & Colis GoColis --}}
                <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5 space-y-3">
                    <h4 class="text-sm font-bold text-gray-900 dark:text-white">
                        📦 Historique récent ({{ count($callerData['orders']) }} commandes, {{ count($callerData['parcels']) }} colis)
                    </h4>
                    <div class="max-h-72 overflow-y-auto divide-y divide-gray-100 dark:divide-gray-800 text-xs">
                        @if (! empty($callerData['recent_otps']))
                            @foreach ($callerData['recent_otps'] as $otpRow)
                                <div class="py-2 flex justify-between gap-2">
                                    <div>
                                        <strong>OTP ({{ $otpRow->purpose }})</strong> — <span class="font-mono font-bold text-amber-700 dark:text-amber-300">{{ $otpRow->admin_otp_code ?? '—' }}</span>
                                        <div class="text-gray-500">
                                            Cible : {{ $otpRow->phone }} • Expire : {{ $otpRow->expires_at?->format('d/m/Y H:i') }}
                                        </div>
                                    </div>
                                    <span class="px-2 py-0.5 rounded bg-amber-100 dark:bg-amber-900/40 font-semibold self-center">
                                        {{ ($otpRow->is_used || ($otpRow->expires_at && $otpRow->expires_at->isPast())) ? 'Expiré/Utilisé' : 'Actif' }}
                                    </span>
                                </div>
                            @endforeach
                        @endif

                        @forelse ($callerData['orders'] as $order)
                            <div class="py-2 flex justify-between gap-2">
                                <div>
                                    <strong>GoShop #{{ $order->id }}</strong> — {{ $order->merchant?->name ?? 'Commerce' }}
                                    <div class="text-gray-500">{{ $order->created_at?->format('d/m/Y H:i') }} • {{ number_format((float) $order->products_total, 0, ',', ' ') }} F</div>
                                    <div class="mt-0.5 text-amber-700 dark:text-amber-300 font-mono">
                                        OTP Livraison : <strong>{{ $order->admin_confirmation_code ?? '—' }}</strong>
                                    </div>
                                </div>
                                <span class="px-2 py-0.5 rounded bg-gray-100 dark:bg-gray-800 font-semibold self-center">{{ $order->status }}</span>
                            </div>
                        @empty
                            <p class="text-gray-500 py-2">Aucune commande GoShop.</p>
                        @endforelse

                        @foreach ($callerData['parcels'] as $parcel)
                            <div class="py-2 flex justify-between gap-2">
                                <div>
                                    <strong>GoColis #{{ $parcel->id }}</strong> — {{ $parcel->recipient_name }}
                                    <div class="text-gray-500">{{ $parcel->created_at?->format('d/m/Y H:i') }} • {{ number_format((float) $parcel->shipping_fee, 0, ',', ' ') }} F</div>
                                    <div class="mt-0.5 text-emerald-700 dark:text-emerald-300 font-mono">
                                        OTP Expédition : <strong>{{ $parcel->admin_sender_code ?? '—' }}</strong>
                                        • OTP Livraison : <strong>{{ $parcel->admin_recipient_code ?? '—' }}</strong>
                                    </div>
                                </div>
                                <span class="px-2 py-0.5 rounded bg-emerald-100 dark:bg-emerald-900/40 font-semibold self-center">{{ $parcel->status }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- 5. Journal d'audit immuable de cet utilisateur --}}
            <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5 space-y-2">
                <h4 class="text-sm font-bold text-gray-900 dark:text-white">
                    📜 Journal d'Audit Immuable (15 dernières actions)
                </h4>
                <div class="overflow-x-auto text-xs">
                    <table class="w-full text-left">
                        <thead class="text-gray-500 border-b border-gray-200 dark:border-gray-700">
                            <tr>
                                <th class="py-2 pr-3">Date / Heure</th>
                                <th class="py-2 pr-3">Conseiller ID</th>
                                <th class="py-2 pr-3">Action</th>
                                <th class="py-2 pr-3">Résultat</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($callerData['audit_trail'] as $log)
                                <tr>
                                    <td class="py-1.5 pr-3">{{ $log->created_at?->format('d/m/Y H:i:s') }}</td>
                                    <td class="py-1.5 pr-3">#{{ $log->user_id ?? 'Système' }}</td>
                                    <td class="py-1.5 pr-3 font-mono">{{ $log->action }}</td>
                                    <td class="py-1.5 pr-3 font-semibold">{{ $log->properties['result'] ?? $log->severity }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="py-2 text-gray-500">Aucune entrée d'audit.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
