@php
    $logoUrl = null;
    $defaultEmoji = '🛵';
    try {
        $branding = app(\App\Services\SettingsService::class)->getBranding();
        $logoUrl = $branding['logo_url'] ?? null;
        $defaultEmoji = $branding['default_emoji'] ?? '🛵';
    } catch (\Throwable) {
    }
@endphp
<div class="flex items-center justify-center gap-3">
    @if (!empty($logoUrl))
        <img
            src="{{ $logoUrl }}"
            alt="GoLivreur"
            style="height: 42px; width: 42px; border-radius: 10px;"
            class="object-contain bg-transparent"
        />
    @else
        <span
            role="img"
            aria-label="GoLivreur"
            style="height: 42px; width: 42px; border-radius: 10px; background: linear-gradient(135deg, #FFB732 0%, #F4A623 100%); box-shadow: 0 6px 14px rgba(244, 166, 35, 0.35); font-size: 22px;"
            class="inline-flex items-center justify-center leading-none select-none"
        >{{ $defaultEmoji }}</span>
    @endif
    <div class="flex flex-col text-left">
        <span class="text-2xl font-black tracking-tight text-gray-900 dark:text-white leading-none">
            <span style="color: #F4A623;">Go</span><span>Livreur</span>
        </span>
        <span class="text-[10px] font-bold uppercase tracking-widest text-amber-600 dark:text-amber-400 mt-1">
            ADMINISTRATION
        </span>
    </div>
</div>
