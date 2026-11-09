<div class="mt-6 rounded-xl border border-gray-200 bg-gray-50/80 p-4 dark:border-gray-800 dark:bg-gray-900/60 text-left">
    <div class="flex items-center gap-2 mb-3 text-amber-600 dark:text-amber-400 font-semibold text-xs uppercase tracking-wider">
        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <span>Identifiants de test (Daloa)</span>
    </div>
    <div class="space-y-2.5 text-xs">
        <!-- Super Admin Card -->
        <div class="flex items-center justify-between bg-white dark:bg-gray-800 p-3 rounded-lg border border-gray-200/80 dark:border-gray-700 shadow-sm">
            <div class="min-w-0 pr-2">
                <p class="font-semibold text-gray-900 dark:text-white flex items-center gap-1.5">
                    <span class="inline-block w-2 h-2 rounded-full bg-emerald-500"></span>
                    Super Admin
                </p>
                <p class="text-gray-600 dark:text-gray-300 font-mono text-[11px] truncate mt-0.5">
                    superadmin@golivreur.ci &bull; SuperAdmin2026!
                </p>
            </div>
            <button
                type="button"
                onclick="window.fillAdminCredentials('superadmin@golivreur.ci', 'SuperAdmin2026!')"
                style="background-color: #f59e0b !important; color: #ffffff !important;"
                class="shrink-0 px-3 py-1.5 text-xs font-semibold rounded-md hover:opacity-90 active:scale-95 transition shadow-sm cursor-pointer"
            >
                Remplir
            </button>
        </div>

        <!-- City Manager Card -->
        <div class="flex items-center justify-between bg-white dark:bg-gray-800 p-3 rounded-lg border border-gray-200/80 dark:border-gray-700 shadow-sm">
            <div class="min-w-0 pr-2">
                <p class="font-semibold text-gray-900 dark:text-white flex items-center gap-1.5">
                    <span class="inline-block w-2 h-2 rounded-full bg-blue-500"></span>
                    City Manager (Daloa)
                </p>
                <p class="text-gray-600 dark:text-gray-300 font-mono text-[11px] truncate mt-0.5">
                    manager.daloa@golivreur.ci &bull; Manager2026!
                </p>
            </div>
            <button
                type="button"
                onclick="window.fillAdminCredentials('manager.daloa@golivreur.ci', 'Manager2026!')"
                class="shrink-0 px-3 py-1.5 text-xs font-semibold rounded-md bg-gray-200 dark:bg-gray-700 text-gray-800 dark:text-gray-200 hover:bg-gray-300 dark:hover:bg-gray-600 active:scale-95 transition shadow-sm cursor-pointer"
            >
                Remplir
            </button>
        </div>
    </div>
</div>

<script>
    window.fillAdminCredentials = function(email, password) {
        var emailInput = document.getElementById('data.email') || document.querySelector('input[type=email]');
        var passInput = document.getElementById('data.password') || document.querySelector('input[type=password]');

        if (emailInput) {
            emailInput.value = email;
            emailInput.dispatchEvent(new Event('input', { bubbles: true }));
            emailInput.dispatchEvent(new Event('change', { bubbles: true }));
        }

        if (passInput) {
            passInput.value = password;
            passInput.dispatchEvent(new Event('input', { bubbles: true }));
            passInput.dispatchEvent(new Event('change', { bubbles: true }));
        }

        // Livewire v3 component state sync
        try {
            var form = document.querySelector('form');
            var compRoot = form ? form.closest('[wire\\:id]') : null;
            var compId = compRoot ? compRoot.getAttribute('wire:id') : null;
            if (compId && window.Livewire) {
                var comp = window.Livewire.find(compId);
                if (comp) {
                    comp.set('data.email', email);
                    comp.set('data.password', password);
                }
            }
        } catch (e) {
            console.error('Error syncing Livewire credentials', e);
        }
    };
</script>
