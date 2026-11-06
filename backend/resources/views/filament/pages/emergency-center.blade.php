<x-filament-panels::page>
    @php
        $stats = $this->stats;
        $cities = $this->cities;
    @endphp

    {{-- 1. Panneau Récapitulatif d'État des Urgences --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        {{-- Carte Mode Pluie --}}
        <div class="p-5 rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-sm flex items-center justify-between">
            <div>
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Mode Pluie Météo (+20%)</div>
                <div class="text-2xl font-bold mt-1 {{ $stats['rain_active'] > 0 ? 'text-amber-500' : 'text-gray-700 dark:text-gray-300' }}">
                    {{ $stats['rain_active'] }} / {{ $stats['total_cities'] }} <span class="text-sm font-normal text-gray-500">villes actives</span>
                </div>
                <div class="text-xs text-gray-400 mt-1">
                    {{ $stats['rain_active'] > 0 ? 'Majoration tarifaire appliquée' : 'Conditions météo standard' }}
                </div>
            </div>
            <div class="p-3 rounded-lg {{ $stats['rain_active'] > 0 ? 'bg-amber-100 text-amber-600 dark:bg-amber-950/50' : 'bg-gray-100 text-gray-500 dark:bg-gray-800' }}">
                <x-heroicon-m-cloud class="w-8 h-8" />
            </div>
        </div>

        {{-- Carte Mode Urgence --}}
        <div class="p-5 rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-sm flex items-center justify-between">
            <div>
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Mode Urgence & Crises</div>
                <div class="text-2xl font-bold mt-1 {{ $stats['emergency_active'] > 0 ? 'text-red-600' : 'text-emerald-600' }}">
                    {{ $stats['emergency_active'] }} <span class="text-sm font-normal text-gray-500">zone(s) suspendue(s)</span>
                </div>
                <div class="text-xs text-gray-400 mt-1">
                    {{ $stats['emergency_active'] > 0 ? 'Arrêt d\'urgence de sécurité en cours' : 'Opérations normales sur toutes les zones' }}
                </div>
            </div>
            <div class="p-3 rounded-lg {{ $stats['emergency_active'] > 0 ? 'bg-red-100 text-red-600 dark:bg-red-950/50' : 'bg-emerald-100 text-emerald-600 dark:bg-emerald-950/50' }}">
                <x-heroicon-m-exclamation-triangle class="w-8 h-8" />
            </div>
        </div>

        {{-- Carte Codes Bloqués --}}
        <div class="p-5 rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-sm flex items-center justify-between">
            <div>
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Codes OTP Verrouillés (3 erreurs)</div>
                <div class="text-2xl font-bold mt-1 {{ ($stats['locked_orders'] + $stats['locked_parcels']) > 0 ? 'text-red-500' : 'text-gray-700 dark:text-gray-300' }}">
                    {{ $stats['locked_orders'] + $stats['locked_parcels'] }} <span class="text-sm font-normal text-gray-500">course(s) bloquée(s)</span>
                </div>
                <div class="text-xs text-gray-400 mt-1">
                    {{ $stats['locked_orders'] }} GoShop · {{ $stats['locked_parcels'] }} GoColis
                </div>
            </div>
            <div class="p-3 rounded-lg {{ ($stats['locked_orders'] + $stats['locked_parcels']) > 0 ? 'bg-red-100 text-red-600 dark:bg-red-950/50' : 'bg-gray-100 text-gray-500 dark:bg-gray-800' }}">
                <x-heroicon-m-lock-closed class="w-8 h-8" />
            </div>
        </div>
    </div>

    {{-- 2. Tableau de Contrôle Rapide par Ville --}}
    <div class="rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-sm overflow-hidden">
        <div class="p-5 border-b border-gray-200 dark:border-gray-800 flex items-center justify-between">
            <div>
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Pilotage Direct des Villes & Zones</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">Basculez instantanément le Mode Pluie ou le Mode Urgence par zone géographique en 1 clic.</p>
            </div>
            <div class="text-xs text-gray-400">
                Mise à jour en temps réel
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800/50 text-gray-600 dark:text-gray-300 font-semibold border-b border-gray-200 dark:border-gray-800">
                    <tr>
                        <th class="py-3.5 px-4">Ville / Zone</th>
                        <th class="py-3.5 px-4">Statut Opérationnel</th>
                        <th class="py-3.5 px-4">Mode Pluie (+20%)</th>
                        <th class="py-3.5 px-4">Mode Urgence (Interruption)</th>
                        <th class="py-3.5 px-4">Alerte Urgence en cours</th>
                        <th class="py-3.5 px-4 text-right">Actions Rapides</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse($cities as $city)
                        <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                            <td class="py-3 px-4 font-semibold text-gray-900 dark:text-white">
                                {{ $city->name }}
                            </td>
                            <td class="py-3 px-4">
                                @if($city->is_active)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-400">
                                        ✓ Ouverte
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-400">
                                        Fermée
                                    </span>
                                @endif
                            </td>
                            <td class="py-3 px-4">
                                @if($city->rain_mode_active)
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300">
                                        <x-heroicon-m-cloud class="w-3.5 h-3.5" /> Pluie Active (+20%)
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400">
                                        Désactivé
                                    </span>
                                @endif
                            </td>
                            <td class="py-3 px-4">
                                @if($city->emergency_mode_active)
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-300">
                                        <x-heroicon-m-exclamation-triangle class="w-3.5 h-3.5" /> Urgence Enclenchée
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400">
                                        Opérations Normales
                                    </span>
                                @endif
                            </td>
                            <td class="py-3 px-4 text-xs text-gray-500 max-w-xs truncate">
                                {{ $city->emergency_message ?? '—' }}
                            </td>
                            <td class="py-3 px-4 text-right space-x-2">
                                {{-- Bascule Mode Pluie --}}
                                <button
                                    type="button"
                                    wire:click="toggleCityRain({{ $city->id }})"
                                    class="inline-flex items-center px-2.5 py-1.5 text-xs font-medium rounded-lg transition-colors border {{ $city->rain_mode_active ? 'border-amber-300 text-amber-700 bg-amber-50 hover:bg-amber-100 dark:bg-amber-950/40 dark:text-amber-300' : 'border-gray-300 text-gray-700 bg-white hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:bg-gray-800' }}"
                                >
                                    <x-heroicon-m-cloud class="w-3.5 h-3.5 mr-1" />
                                    {{ $city->rain_mode_active ? 'Couper Pluie' : 'Activer Pluie' }}
                                </button>

                                {{-- Bascule Mode Urgence --}}
                                <button
                                    type="button"
                                    wire:click="toggleCityEmergency({{ $city->id }})"
                                    class="inline-flex items-center px-2.5 py-1.5 text-xs font-medium rounded-lg transition-colors border {{ $city->emergency_mode_active ? 'border-emerald-300 text-emerald-700 bg-emerald-50 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:text-emerald-300' : 'border-red-300 text-red-700 bg-red-50 hover:bg-red-100 dark:bg-red-950/40 dark:text-red-300' }}"
                                >
                                    <x-heroicon-m-exclamation-triangle class="w-3.5 h-3.5 mr-1" />
                                    {{ $city->emergency_mode_active ? 'Lever Urgence' : 'Déclencher Urgence' }}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-6 text-center text-gray-500">
                                Aucune ville enregistrée dans la plateforme.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>

