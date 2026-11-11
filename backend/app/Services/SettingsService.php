<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Service centralisé de gestion des Paramètres GoLivreur.
 *
 * - Lecture ultra-rapide via Cache (Redis)
 * - Support de la surcharge locale par ville (city_id nullable)
 * - Traçabilité obligatoire dans activity_logs à chaque modification
 * - Valeurs par défaut du Cahier des Charges (CDC)
 */
class SettingsService
{
    private const CACHE_TTL_SECONDS = 86400; // 24 heures

    // ─── 10 Sections par défaut conformes au CDC ──────────────────────────────
    public const DEFAULTS = [
        'tarifs_goshop' => [
            'bareme' => [
                ['max_km' => 2,  'normal' => 500,  'pointe' => 700,  'pluie' => 1000],
                ['max_km' => 4,  'normal' => 800,  'pointe' => 1000, 'pluie' => 1300],
                ['max_km' => 7,  'normal' => 1200, 'pointe' => 1500, 'pluie' => 1800],
                ['max_km' => 9999, 'normal' => 1500, 'pointe' => 1800, 'pluie' => 2000],
            ],
            'heures_pointe' => [
                ['debut' => '11:30', 'fin' => '14:00'],
                ['debut' => '18:00', 'fin' => '21:00'],
            ],
        ],

        'tarifs_gocolis' => [
            'bareme' => [
                'petit' => ['base' => 800,  'par_km' => 200],
                'moyen' => ['base' => 1200, 'par_km' => 300],
                'grand' => ['base' => 2000, 'par_km' => 400],
            ],
            'seuil_assurance' => 20000,
            'taux_assurance' => 3.0, // 3%
        ],

        'commissions' => [
            'merchant_defaut' => 15.0, // 15% sur produits
            'livreur_goshop' => 20.0, // Plateforme garde 20% frais livraison
            'plateforme_gocolis' => 25.0, // 25% des frais d'expédition
        ],

        'delais_regles' => [
            'acceptation_commerce_secondes' => 45,
            'acceptation_livreur_secondes' => 30,
            'refus_avant_penalite' => 3,
            'duree_penalite_minutes' => 15,
            'erreurs_code_avant_blocage' => 3,
            'rayon_recherche_livreur_km' => 5.0,
        ],

        'remboursements' => [
            'plafonds' => [
                'city_support' => 2000,
                'city_finance' => 10000,
                'city_manager' => 10000,
                'national_support' => 2000,
                'national_finance' => 50000,
                'super_admin' => 50000,
                'dg_national' => 50000,
            ],
        ],

        'modes_speciaux' => [
            'pluie_automatique' => true,
            'meteo_check_interval_minutes' => 15,
            'urgence_bandeau_message' => '⚠️ Mode Urgence activé. Les livraisons peuvent être ralenties.',
        ],

        'paiements' => [
            'modes_actifs' => ['orange_money', 'cash', 'wave', 'mtn_momo'],
        ],

        'sms' => [
            'seuil_alerte_mensuel' => 2500,
            'alert_email' => 'francis@golivreur.ci',
        ],

        'securite' => [
            'session_duree_minutes' => 120,
            'tentatives_otp_max' => 3,
            'otp_validite_minutes' => 5,
        ],

        'contenus' => [
            'support_whatsapp' => '+2250700000002',
            'faq_url' => 'https://golivreur.ci/faq',
            'app_version_min' => '1.0.0',
        ],

        'branding' => [
            'logo_path' => null,
            'default_emoji' => '🛵',
        ],

        'courses.max_actives' => 1,
        'wallet.solde_minimum_cash' => 10000,
    ];

    /**
     * Récupère la valeur d'un paramètre (avec gestion ville + cache).
     */
    public function get(string $key, ?int $cityId = null, mixed $default = null): mixed
    {
        $cacheKey = $this->buildCacheKey($key, $cityId);

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($key, $cityId, $default) {
            // 1. Chercher surcharge spécifique pour la ville
            if ($cityId !== null) {
                $citySetting = Setting::where('key', $key)->where('city_id', $cityId)->first();
                if ($citySetting !== null) {
                    return $citySetting->value;
                }
            }

            // 2. Chercher paramètre global
            $globalSetting = Setting::where('key', $key)->whereNull('city_id')->first();
            if ($globalSetting !== null) {
                return $globalSetting->value;
            }

            // 3. Valeur par défaut fournie ou définie dans le CDC
            return $default ?? self::DEFAULTS[$key] ?? null;
        });
    }

    /**
     * Enregistre ou met à jour un paramètre avec traçabilité dans activity_logs.
     */
    public function set(
        string $key,
        mixed $value,
        ?int $cityId = null,
        ?int $userId = null,
        ?string $description = null
    ): Setting {
        $query = Setting::where('key', $key);
        if ($cityId !== null) {
            $query->where('city_id', $cityId);
        } else {
            $query->whereNull('city_id');
        }

        $setting = $query->first();
        $oldValue = $setting ? $setting->value : null;

        if ($setting) {
            $setting->update([
                'value' => $value,
                'description' => $description ?? $setting->description,
            ]);
        } else {
            $setting = Setting::create([
                'key' => $key,
                'value' => $value,
                'city_id' => $cityId,
                'description' => $description,
            ]);
        }

        // Invalider le cache
        $this->flushCache($key, $cityId);

        // Journaliser la modification dans activity_logs
        try {
            ActivityLog::create([
                'user_id' => $userId ?? auth()->id(),
                'action' => 'setting_updated',
                'entity_type' => 'setting',
                'entity_id' => $setting->id,
                'properties' => [
                    'key' => $key,
                    'city_id' => $cityId,
                    'old_value' => $oldValue,
                    'new_value' => $value,
                ],
                'ip_address' => request()->ip(),
                'severity' => in_array($key, ['tarifs_goshop', 'tarifs_gocolis', 'commissions', 'remboursements'], true)
                    ? 'critical'
                    : 'warning',
            ]);
        } catch (\Exception $e) {
            Log::warning("Impossible d'écrire l'activité setting_updated : ".$e->getMessage());
        }

        return $setting;
    }

    /**
     * Retourne l'identité visuelle GoLivreur (logo personnalisé si existant, sinon emoji par défaut).
     *
     * @return array{logo_path: ?string, logo_url: ?string, default_emoji: string}
     */
    public function getBranding(): array
    {
        $branding = $this->get('branding', null, self::DEFAULTS['branding']);
        $logoPath = is_array($branding) ? ($branding['logo_path'] ?? null) : null;

        if (is_array($logoPath)) {
            $logoPath = reset($logoPath) ?: null;
        }

        $hasLogo = is_string($logoPath)
            && $logoPath !== ''
            && Storage::disk('public')->exists($logoPath);

        $defaultEmoji = is_array($branding) && ! empty($branding['default_emoji'])
            ? trim((string) $branding['default_emoji'])
            : '🛵';

        if ($defaultEmoji === '') {
            $defaultEmoji = '🛵';
        }

        return [
            'logo_path' => $hasLogo ? $logoPath : null,
            'logo_url' => $hasLogo ? url('storage/'.$logoPath) : null,
            'default_emoji' => $defaultEmoji,
        ];
    }

    public function getAppLogoUrl(): ?string
    {
        return $this->getBranding()['logo_url'];
    }

    public function getDefaultEmoji(): string
    {
        return $this->getBranding()['default_emoji'];
    }

    /**
     * Récupère tous les paramètres (fusionnant défauts et surcharges).
     */
    public function getAll(?int $cityId = null): array
    {
        $result = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $result[$key] = $this->get($key, $cityId);
        }

        return $result;
    }

    /**
     * Vide le cache associé à un paramètre.
     */
    public function flushCache(string $key, ?int $cityId = null): void
    {
        Cache::forget($this->buildCacheKey($key, $cityId));
        if ($cityId !== null) {
            // Invalider également la clé globale pour forcer la synchronisation
            Cache::forget($this->buildCacheKey($key, null));
        }
    }

    private function buildCacheKey(string $key, ?int $cityId): string
    {
        return 'golivreur_setting_'.$key.'_'.($cityId ?? 'global');
    }
}
