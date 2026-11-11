<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;

class User extends Authenticatable implements JWTSubject, FilamentUser
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

    protected $fillable = [
        'name', 'phone', 'role', 'role_habilitation_id', 'city_id',
        'default_address', 'language', 'notifications_enabled',
        'device_token', 'session_token', 'is_active',
        'phone_verified_at', 'avatar_url', 'photo_path',
        'email', 'password', 'two_factor_enabled',
        'last_login_ip', 'last_login_at',
    ];

    protected $hidden = [
        'password', 'session_token', 'remember_token',
    ];

    protected $casts = [
        'is_active'              => 'boolean',
        'two_factor_enabled'     => 'boolean',
        'notifications_enabled'  => 'boolean',
        'phone_verified_at'      => 'datetime',
        'last_login_at'          => 'datetime',
    ];

    protected $appends = [
        'photo_url',
    ];

    /**
     * URL publique de la photo de profil utilisateur.
     */
    public function getPhotoUrlAttribute(): ?string
    {
        if (!empty($this->attributes['photo_path'])) {
            return url('storage/' . $this->attributes['photo_path']);
        }

        return $this->attributes['avatar_url'] ?? null;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->role === 'super_admin') {
            return true;
        }

        return in_array($this->role, self::ADMIN_ROLES, true) || $this->role_habilitation_id !== null;
    }

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
            'national_finance' => in_array($tabKey, ['dashboard', 'finances', 'refunds', 'activity_logs'], true),
            'city_manager' => in_array($tabKey, ['dashboard', 'orders', 'parcels', 'merchants', 'couriers', 'clients', 'pending_validations', 'disputes', 'emergency_center', 'administrators', 'cities'], true),
            'city_support', 'national_support' => in_array($tabKey, ['dashboard', 'orders', 'parcels', 'merchants', 'couriers', 'clients', 'pending_validations', 'disputes', 'refunds'], true),
            'city_agent' => in_array($tabKey, ['dashboard', 'merchants', 'couriers', 'pending_validations'], true),
            default => false,
        };
    }

    // ─── JWT ──────────────────────────────────────────────────────────────────

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [
            'role'    => $this->role,
            'city_id' => $this->city_id,
        ];
    }

    // ─── Relations ────────────────────────────────────────────────────────────

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function merchant(): HasOne
    {
        return $this->hasOne(Merchant::class);
    }

    public function courier(): HasOne
    {
        return $this->hasOne(Courier::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'client_id');
    }

    public function parcels(): HasMany
    {
        return $this->hasMany(Parcel::class, 'sender_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

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

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
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
            'super_admin', 'national_dg', 'national_finance',
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
            'super_admin', 'national_dg', 'national_finance',
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
            'super_admin', 'national_dg', 'national_finance',
            'national_tech', 'national_support',
        ]);
    }

    // Masquage du numéro de téléphone pour les logs : 0700000001 → 07****001
    public function getMaskedPhoneAttribute(): string
    {
        return substr($this->phone, 0, 2)
            . '****'
            . substr($this->phone, -3);
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
            'super_admin', 'national_dg', 'national_finance',
            'national_tech', 'national_support' => 120, // 2 heures en minutes
            'city_manager', 'city_finance'       => 240, // 4 heures
            'city_support', 'city_agent'         => 480, // 8 heures
            default                              => 60,  // 1 heure (JWT)
        };
    }
}
