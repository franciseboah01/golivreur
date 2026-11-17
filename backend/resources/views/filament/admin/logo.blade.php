@php
    $logoUrl = null;
    $defaultEmoji = '🛵';
    $isLogin = request()->routeIs('filament.*.auth.*') || request()->is('admin/login');
    $imgSize = $isLogin ? 68 : 38;
    try {
        $branding = app(\App\Services\SettingsService::class)->getBranding();
        $logoUrl = $branding['logo_url'] ?? null;
        $defaultEmoji = $branding['default_emoji'] ?? '🛵';
    } catch (\Throwable) {
    }
@endphp
<div class="flex flex-col items-center justify-center text-center gap-1.5 py-1">
    @if (!empty($logoUrl))
        <img
            src="{{ $logoUrl }}"
            alt="GoLivreur"
            style="height: {{ $imgSize }}px; width: {{ $imgSize }}px;"
            class="object-contain bg-transparent mx-auto"
        />
    @else
        <span
            role="img"
            aria-label="GoLivreur"
            style="height: {{ $imgSize }}px; width: {{ $imgSize }}px; border-radius: {{ round($imgSize * 0.24) }}px; background: linear-gradient(135deg, #FFB732 0%, #F4A623 100%); box-shadow: 0 6px 14px rgba(244, 166, 35, 0.35); font-size: {{ round($imgSize * 0.54) }}px;"
            class="inline-flex items-center justify-center leading-none select-none mx-auto"
        >{{ $defaultEmoji }}</span>
    @endif
    <span class="{{ $isLogin ? 'text-2xl mt-1' : 'text-base' }} font-extrabold tracking-tight text-gray-900 dark:text-white leading-none">
        <span style="color: #F4A623;">Go</span><span>Livreur</span>
    </span>
    <span
        style="padding: 2px 12px; border-radius: 9999px; background: rgba(244, 166, 35, 0.12); border: 1px solid rgba(244, 166, 35, 0.30); color: #D97706; font-size: 10px; font-weight: 700; letter-spacing: 0.16em;"
        class="uppercase leading-tight"
    >
        ADMINISTRATION
    </span>
</div>
