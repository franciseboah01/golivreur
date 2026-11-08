<div class="mt-6 rounded-xl border border-amber-200 bg-amber-50/70 p-4 dark:border-amber-900/50 dark:bg-amber-950/20 text-left">
    <div class="flex items-center gap-2 mb-2 text-amber-800 dark:text-amber-300 font-semibold text-xs uppercase tracking-wider">
        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <span>Identifiants de test (Daloa)</span>
    </div>
    <div class="space-y-2 text-xs">
        <div class="flex items-center justify-between bg-white dark:bg-gray-800/80 p-2.5 rounded-lg border border-gray-100 dark:border-gray-700 shadow-sm">
            <div>
                <p class="font-semibold text-gray-900 dark:text-white">Super Admin (Accès complet)</p>
                <p class="text-gray-500 dark:text-gray-400 font-mono text-[11px]">superadmin@golivreur.ci &bull; SuperAdmin2026!</p>
            </div>
            <button
                type="button"
                onclick="
                    let e = document.querySelector('input[type=email]'); if(e){ e.value='superadmin@golivreur.ci'; e.dispatchEvent(new Event('input')); }
                    let p = document.querySelector('input[type=password]'); if(p){ p.value='SuperAdmin2026!'; p.dispatchEvent(new Event('input')); }
                "
                class="px-2.5 py-1 text-xs font-semibold rounded bg-amber-500 text-white hover:bg-amber-600 transition shadow-sm"
            >
                Remplir
            </button>
        </div>
        <div class="flex items-center justify-between bg-white dark:bg-gray-800/80 p-2.5 rounded-lg border border-gray-100 dark:border-gray-700 shadow-sm">
            <div>
                <p class="font-semibold text-gray-900 dark:text-white">City Manager (Daloa)</p>
                <p class="text-gray-500 dark:text-gray-400 font-mono text-[11px]">manager.daloa@golivreur.ci &bull; Manager2026!</p>
            </div>
            <button
                type="button"
                onclick="
                    let e = document.querySelector('input[type=email]'); if(e){ e.value='manager.daloa@golivreur.ci'; e.dispatchEvent(new Event('input')); }
                    let p = document.querySelector('input[type=password]'); if(p){ p.value='Manager2026!'; p.dispatchEvent(new Event('input')); }
                "
                class="px-2.5 py-1 text-xs font-semibold rounded bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200 hover:bg-gray-200 transition"
            >
                Remplir
            </button>
        </div>
    </div>
</div>
