<?php

namespace App\Models;

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Services\SettingsService;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

/**
 * @property-read string|null $photo_url
 */
class User extends Authenticatable implements FilamentUser, JWTSubject
{
    use HasFactory, SoftDeletes;

    public const ADMIN_ROLES = [
        'super_admin',
        'dg_national',
        'national_finance',
        'national_tech',
        'national_support',
        'city_manager',
        'city_finance',
        'city_support',
        'city_agent',
        'admin',
    ];

    public const USER_ROLES = [
        'client',
        'merchant',
        'driver',
        'courier',
    ];

    public const CODE_MANAGEMENT_LOCAL_ROLES = [
        'city_support',
        'city_finance',
        'city_manager',
    ];

    public const CODE_MANAGEMENT_NATIONAL_ROLES = [
        'national_support',
        'national_finance',
        'dg_national',
        'super_admin',
    ];

    protected $fillable = [
        'name', 'phone', 'role', 'role_habilitation_id', 'city_id',
        'default_address', 'language', 'notifications_enabled',
        'device_token', 'session_token', 'is_active',
        'phone_verified_at', 'avatar_url', 'photo_path',
        'email', 'password', 'two_factor_enabled',
        'last_login_ip', 'last_login_at',
    ];

    protected $hidden = [
        'password', 'session_token', 'remember_token', 'device_token',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'two_factor_enabled' => 'boolean',
        'notifications_enabled' => 'boolean',
        'phone_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
    ];

    protected $appends = [
        'photo_url',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $user): void {
            if (is_string($user->phone) && $user->phone !== '') {
                $user->phone = self::normalizePhone($user->phone);
            }
        });

        static::updating(function (self $user): void {
            if (! $user->isDirty('phone')) {
                return;
            }

            $oldPhone = (string) ($user->getOriginal('phone') ?? '');
            if ($oldPhone === '' || $oldPhone === (string) $user->phone) {
                return;
            }

            $actor = auth()->user();
            if ($actor instanceof self && $actor->is_admin && (int) $actor->id !== (int) $user->id) {
                $user->device_token = null;
                $user->session_token = 'revoked_'.Str::uuid()->toString();
            }
        });

        static::updated(function (self $user): void {
            if (! $user->wasChanged('phone')) {
                return;
            }

            $oldPhone = (string) ($user->getOriginal('phone') ?? '');
            if ($oldPhone !== '') {
                OtpCode::where('phone', $oldPhone)->delete();
            }

            $actor = auth()->user();
            if ($actor instanceof self && $actor->is_admin && (int) $actor->id !== (int) $user->id) {
                AuthController::revokeUserRefreshTokens((int) $user->id);

                ActivityLog::create([
                    'user_id' => $actor->id,
                    'action' => 'user_phone_changed_by_admin',
                    'entity_type' => 'user',
                    'entity_id' => $user->id,
                    'properties' => [
                        'old_phone_masked' => substr($oldPhone, 0, 2).'****'.substr($oldPhone, -3),
                        'new_phone_masked' => substr((string) $user->phone, 0, 2).'****'.substr((string) $user->phone, -3),
                        'sessions_revoked' => true,
                        'device_token_cleared' => true,
                    ],
                    'ip_address' => request()->ip(),
                    'severity' => 'warning',
                ]);
            }
        });
    }

    public static function normalizePhone(string $raw): string
    {
        $cleaned = preg_replace('/[\s\.\-\(\)]+/', '', trim($raw)) ?? '';
        if (str_starts_with($cleaned, '+225')) {
            return substr($cleaned, 4);
        }
        if (str_starts_with($cleaned, '00225')) {
            return substr($cleaned, 5);
        }
        if (strlen($cleaned) === 13 && str_starts_with($cleaned, '225')) {
            return substr($cleaned, 3);
        }

        return $cleaned;
    }

    /**
     * URL publique de la photo de profil utilisateur.
     */
    public function getPhotoUrlAttribute(): ?string
    {
        if (! empty($this->attributes['photo_path'])) {
            return url('storage/'.$this->attributes['photo_path']);
        }

        return $this->attributes['avatar_url'] ?? null;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->role === 'super_admin') {
            return true;
        }

        return in_array($this->role, self::ADMIN_ROLES, true) || $this->role_habilitation_id !== null;
    }

    /**
     * @return BelongsTo<RoleHabilitation, $this>
     */
    public function roleHabilitation(): BelongsTo
    {
        return $this->belongsTo(RoleHabilitation::class, 'role_habilitation_id');
    }

    /**
     * Vérifie si l'administrateur a accès à un onglet donné selon son niveau d'habilitation.
     */
    public function hasHabilitationTab(string $tabKey): bool
    {
        if ($this->role === 'super_admin') {
            return true;
        }

        if ($this->roleHabilitation) {
            return $this->roleHabilitation->hasOnglet($tabKey);
        }

        // Rôles opérationnels hérités par défaut si pas encore assigné
        return match ($this->role) {
            'dg_national' => true,
            'national_finance' => in_array($tabKey, ['dashboard', 'finances', 'refunds', 'activity_logs', 'webhook_logs'], true),
            'national_tech' => in_array($tabKey, ['dashboard', 'webhook_logs', 'activity_logs', 'settings'], true),
            'city_manager' => in_array($tabKey, ['dashboard', 'orders', 'parcels', 'merchants', 'couriers', 'clients', 'pending_validations', 'disputes', 'administrators', 'cities', 'refunds'], true),
            'city_finance' => in_array($tabKey, ['dashboard', 'finances', 'refunds'], true),
            'city_support', 'national_support' => in_array($tabKey, ['dashboard', 'orders', 'parcels', 'merchants', 'couriers', 'clients', 'pending_validations', 'disputes', 'refunds'], true),
            'city_agent' => in_array($tabKey, ['dashboard', 'merchants', 'couriers', 'pending_validations'], true),
            default => false,
        };
    }

    // ─── Multi-Rôles & Sécurité (sans modification de schéma) ─────────────────

    /**
     * Retourne l'ensemble des rôles actifs cumulés par cette identité unique.
     *
     * @return list<string>
     */
    public function effectiveRoles(): array
    {
        $roles = [];

        if (is_string($this->role) && $this->role !== '') {
            $roles[] = $this->role === 'courier' ? 'driver' : $this->role;
        }

        // Un profil merchant actif confère le rôle merchant
        if ($this->relationLoaded('merchant')) {
            if ($this->merchant && $this->merchant->is_active) {
                $roles[] = 'merchant';
            }
        } elseif ($this->merchant()->where('is_active', true)->exists()) {
            $roles[] = 'merchant';
        }

        // Un profil courier actif confère le rôle driver
        if ($this->relationLoaded('courier')) {
            if ($this->courier && $this->courier->is_active) {
                $roles[] = 'driver';
            }
        } elseif ($this->courier()->where('is_active', true)->exists()) {
            $roles[] = 'driver';
        }

        // Rôles complémentaires stockés dans settings (ex: user_extra_roles:<id>)
        $extraRoles = app(SettingsService::class)->get("user_extra_roles:{$this->id}", null, []);
        if (is_array($extraRoles)) {
            foreach ($extraRoles as $extra) {
                if (is_string($extra) && $extra !== '') {
                    $roles[] = $extra === 'courier' ? 'driver' : $extra;
                }
            }
        }

        return array_values(array_unique($roles));
    }

    /**
     * Attribue un rôle supplémentaire à cette identité unique (ex: client + merchant/driver).
     */
    public function grantExtraRole(string $role, ?int $actorId = null): void
    {
        $normalized = $role === 'courier' ? 'driver' : strtolower(trim($role));
        if ($normalized === '' || in_array($normalized, $this->effectiveRoles(), true)) {
            return;
        }

        $settings = app(SettingsService::class);
        $current = $settings->get("user_extra_roles:{$this->id}", null, []);
        $list = is_array($current) ? $current : [];
        $list[] = $normalized;
        $settings->set("user_extra_roles:{$this->id}", array_values(array_unique($list)), null, $actorId ?? $this->id);
    }

    public function hasRole(string $role): bool
    {
        $target = strtolower(trim($role));
        $roles = $this->effectiveRoles();

        if ($target === 'admin') {
            return in_array($this->role, self::ADMIN_ROLES, true);
        }

        if ($target === 'driver' || $target === 'courier') {
            return in_array('driver', $roles, true) || in_array('courier', $roles, true);
        }

        return in_array($target, $roles, true);
    }

    /**
     * @param  array<int, string>  $roles
     */
    public function hasAnyRole(array $roles): bool
    {
        foreach ($roles as $role) {
            if ($this->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Indique si l'utilisateur doit obligatoirement changer son mot de passe à la prochaine connexion.
     */
    public function mustChangePassword(): bool
    {
        $flags = app(SettingsService::class)->get("user_security_flags:{$this->id}", null, []);

        return is_array($flags) && ! empty($flags['must_change_password']);
    }

    public function setMustChangePassword(bool $required, ?int $actorId = null): void
    {
        $settings = app(SettingsService::class);
        $flags = $settings->get("user_security_flags:{$this->id}", null, []);
        $data = is_array($flags) ? $flags : [];
        $data['must_change_password'] = $required;
        $data['password_reset_updated_at'] = now()->toIso8601String();
        $settings->set("user_security_flags:{$this->id}", $data, null, $actorId ?? $this->id);
    }

    public function pinResetRequiredAt(): ?string
    {
        $flags = app(SettingsService::class)->get("user_security_flags:{$this->id}", null, []);

        return is_array($flags) && isset($flags['pin_reset_at']) && is_string($flags['pin_reset_at'])
            ? $flags['pin_reset_at']
            : null;
    }

    public function markPinResetRequired(?int $actorId = null): void
    {
        $settings = app(SettingsService::class);
        $flags = $settings->get("user_security_flags:{$this->id}", null, []);
        $data = is_array($flags) ? $flags : [];
        $data['pin_reset_at'] = now()->toIso8601String();
        $settings->set("user_security_flags:{$this->id}", $data, null, $actorId ?? $this->id);
    }

    // ─── JWT ──────────────────────────────────────────────────────────────────

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [
            'role' => $this->role,
            'roles' => $this->effectiveRoles(),
            'city_id' => $this->city_id,
        ];
    }

    // ─── Relations ────────────────────────────────────────────────────────────

    /**
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * @return HasOne<Merchant, $this>
     */
    public function merchant(): HasOne
    {
        return $this->hasOne(Merchant::class);
    }

    /**
     * @return HasOne<Courier, $this>
     */
    public function courier(): HasOne
    {
        return $this->hasOne(Courier::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'client_id');
    }

    /**
     * @return HasMany<Parcel, $this>
     */
    public function parcels(): HasMany
    {
        return $this->hasMany(Parcel::class, 'sender_id');
    }

    /**
     * @return HasMany<Notification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * @return HasMany<ClientAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(ClientAddress::class);
    }

    public function favoriteMerchants(): BelongsToMany
    {
        return $this->belongsToMany(Merchant::class, 'client_favorite_merchants', 'user_id', 'merchant_id')
            ->withTimestamps();
    }

    public function adminSessions(): HasMany
    {
        return $this->hasMany(AdminSession::class);
    }

    /**
     * @return HasMany<ActivityLog, $this>
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    /**
     * Dernier code OTP actif (connexion, inscription, réinitialisation, support, changement numéro)
     * associé au téléphone de l'utilisateur pour consultation côté administration.
     */
    public function latestOtpRecord(): ?OtpCode
    {
        if (empty($this->phone)) {
            return null;
        }

        return OtpCode::where('phone', $this->phone)
            ->latest('id')
            ->first();
    }

    public function getAdminLatestOtpCodeAttribute(): ?string
    {
        $record = $this->latestOtpRecord();
        if (! $record) {
            return null;
        }

        $code = $record->admin_otp_code;
        if ($code === null) {
            return null;
        }

        $isExpired = $record->expires_at !== null && $record->expires_at->isPast();
        if ($record->is_used || $isExpired) {
            return "{$code} (expiré/utilisé)";
        }

        return $code;
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->whereNull('deleted_at');
    }

    public function scopeByRole($query, string $role)
    {
        return $query->where('role', $role);
    }

    public function scopeByCity($query, int $cityId)
    {
        return $query->where('city_id', $cityId);
    }

    public function scopeAdmins($query)
    {
        return $query->whereIn('role', [
            'super_admin', 'dg_national', 'national_finance',
            'national_tech', 'national_support',
            'city_manager', 'city_finance', 'city_support', 'city_agent',
        ]);
    }

    public function scopeLocalAdmins($query, int $cityId)
    {
        return $query->whereIn('role', [
            'city_manager', 'city_finance', 'city_support', 'city_agent',
        ])->where('city_id', $cityId);
    }

    // ─── Accesseurs ───────────────────────────────────────────────────────────

    public function getIsAdminAttribute(): bool
    {
        return in_array($this->role, [
            'super_admin', 'dg_national', 'national_finance',
            'national_tech', 'national_support',
            'city_manager', 'city_finance', 'city_support', 'city_agent',
        ]);
    }

    public function getIsLocalAdminAttribute(): bool
    {
        return in_array($this->role, [
            'city_manager', 'city_finance', 'city_support', 'city_agent',
        ]);
    }

    public function getIsNationalAdminAttribute(): bool
    {
        return in_array($this->role, [
            'super_admin', 'dg_national', 'national_finance',
            'national_tech', 'national_support',
        ]);
    }

    // Masquage du numéro de téléphone pour les logs : 0700000001 → 07****001
    public function getMaskedPhoneAttribute(): string
    {
        return substr($this->phone, 0, 2)
            .'****'
            .substr($this->phone, -3);
    }

    public function getUnreadNotificationsCountAttribute(): int
    {
        return $this->notifications()->where('is_read', false)->count();
    }

    // ─── Méthodes métier ──────────────────────────────────────────────────────

    public function isVerified(): bool
    {
        return $this->phone_verified_at !== null;
    }

    public function canManageCodes(): bool
    {
        return in_array($this->role, [
            ...self::CODE_MANAGEMENT_LOCAL_ROLES,
            ...self::CODE_MANAGEMENT_NATIONAL_ROLES,
        ], true);
    }

    public function canAccessCity(int $cityId): bool
    {
        // Super admin et admins nationaux accèdent à toutes les villes
        if ($this->is_national_admin) {
            return true;
        }

        return $this->city_id === $cityId;
    }

    public function getSessionTimeout(): int
    {
        return match ($this->role) {
            'super_admin', 'dg_national', 'national_finance',
            'national_tech', 'national_support' => 120, // 2 heures en minutes
            'city_manager', 'city_finance' => 240, // 4 heures
            'city_support', 'city_agent' => 480, // 8 heures
            default => 60,  // 1 heure (JWT)
        };
    }
}
