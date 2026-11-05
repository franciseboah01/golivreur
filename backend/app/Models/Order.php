<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $fillable = [
        'client_id', 'merchant_id', 'courier_id', 'city_id',
        'status', 'products_total', 'delivery_fee',
        'platform_commission', 'merchant_amount', 'courier_amount',
        'rain_mode_applied', 'peak_hours_applied',
        'payment_method', 'payment_status', 'payment_reference',
        'confirmation_code', 'confirmation_attempts', 'confirmation_locked',
        'confirmation_unlocked_by',
        'delivery_address', 'delivery_lat', 'delivery_lng',
        'pickup_lat', 'pickup_lng', 'special_instructions',
        'accepted_at', 'ready_at', 'picked_up_at',
        'delivered_at', 'cancelled_at', 'cancel_reason',
    ];

    protected $casts = [
        'rain_mode_applied'    => 'boolean',
        'peak_hours_applied'   => 'boolean',
        'confirmation_locked'  => 'boolean',
        'accepted_at'          => 'datetime',
        'ready_at'             => 'datetime',
        'picked_up_at'         => 'datetime',
        'delivered_at'         => 'datetime',
        'cancelled_at'         => 'datetime',
    ];

    // Statuts possibles
    const STATUS_PENDING    = 'pending';
    const STATUS_ACCEPTED   = 'accepted';
    const STATUS_REFUSED    = 'refused';
    const STATUS_READY      = 'ready';
    const STATUS_PICKED_UP  = 'picked_up';
    const STATUS_IN_TRANSIT = 'in_transit';
    const STATUS_DELIVERED  = 'delivered';
    const STATUS_CANCELLED  = 'cancelled';

    // ─── Relations ────────────────────────────────────────────────────────────

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeByCity($query, int $cityId)
    {
        return $query->where('city_id', $cityId);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            self::STATUS_PENDING, self::STATUS_ACCEPTED,
            self::STATUS_READY, self::STATUS_PICKED_UP, self::STATUS_IN_TRANSIT,
        ]);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }

    public function scopeForClient($query, int $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeForMerchant($query, int $merchantId)
    {
        return $query->where('merchant_id', $merchantId);
    }

    public function scopeForCourier($query, int $courierId)
    {
        return $query->where('courier_id', $courierId);
    }

    // ─── Accesseurs ───────────────────────────────────────────────────────────

    public function getIsDeliverableAttribute(): bool
    {
        return in_array($this->status, [
            self::STATUS_READY, self::STATUS_PICKED_UP, self::STATUS_IN_TRANSIT,
        ]);
    }

    public function getDeliveryDurationMinutesAttribute(): ?int
    {
        if (!$this->picked_up_at || !$this->delivered_at) {
            return null;
        }
        return (int) $this->picked_up_at->diffInMinutes($this->delivered_at);
    }

    public function getTotalClientAttribute(): float
    {
        return $this->products_total + $this->delivery_fee;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Parcel extends Model
{
    protected $fillable = [
        'sender_id', 'courier_id', 'city_id', 'status',
        'size', 'weight_kg', 'declared_value',
        'has_insurance', 'insurance_amount',
        'sender_code', 'sender_code_attempts', 'sender_code_locked',
        'recipient_code', 'recipient_code_attempts', 'recipient_code_locked',
        'recipient_name', 'recipient_phone',
        'delivery_address', 'delivery_lat', 'delivery_lng',
        'pickup_address', 'pickup_lat', 'pickup_lng',
        'description', 'shipping_fee', 'platform_commission', 'courier_amount',
        'payment_method', 'payment_status', 'payment_reference',
        'assignment_attempts',
        'sender_code_unlocked_by', 'recipient_code_unlocked_by',
        'picked_up_at', 'delivered_at', 'cancelled_at',
    ];

    protected $hidden = [
        'sender_code', 'recipient_code',
    ];

    protected $casts = [
        'has_insurance'         => 'boolean',
        'sender_code_locked'    => 'boolean',
        'recipient_code_locked' => 'boolean',
        'picked_up_at'          => 'datetime',
        'delivered_at'          => 'datetime',
        'cancelled_at'          => 'datetime',
    ];

    // Tailles acceptées
    const SIZE_SMALL  = 'petit';
    const SIZE_MEDIUM = 'moyen';
    const SIZE_LARGE  = 'grand';

    // Statuts
    const STATUS_PENDING      = 'pending';
    const STATUS_WAITING      = 'waiting';
    const STATUS_ACCEPTED     = 'accepted';
    const STATUS_AT_SENDER    = 'at_sender';
    const STATUS_PICKED_UP    = 'picked_up';
    const STATUS_IN_TRANSIT   = 'in_transit';
    const STATUS_AT_RECIPIENT = 'at_recipient';
    const STATUS_DELIVERED    = 'delivered';
    const STATUS_REFUSED      = 'refused';
    const STATUS_CANCELLED    = 'cancelled';

    // ─── Relations ────────────────────────────────────────────────────────────

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ParcelPhoto::class);
    }

    public function pickupPhoto(): HasMany
    {
        return $this->hasMany(ParcelPhoto::class)->where('type', 'pickup');
    }

    public function deliveryPhoto(): HasMany
    {
        return $this->hasMany(ParcelPhoto::class)->where('type', 'delivery');
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeByCity($query, int $cityId)
    {
        return $query->where('city_id', $cityId);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            self::STATUS_PENDING, self::STATUS_WAITING,
            self::STATUS_ACCEPTED, self::STATUS_AT_SENDER,
            self::STATUS_PICKED_UP, self::STATUS_IN_TRANSIT,
            self::STATUS_AT_RECIPIENT,
        ]);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }

    // ─── Accesseurs ───────────────────────────────────────────────────────────

    public function getSizeLabelAttribute(): string
    {
        return match ($this->size) {
            self::SIZE_SMALL  => 'Petit (max 1 kg)',
            self::SIZE_MEDIUM => 'Moyen (max 5 kg)',
            self::SIZE_LARGE  => 'Grand (max 15 kg)',
            default           => $this->size,
        };
    }

    public function getNeedsInsuranceAttribute(): bool
    {
        return $this->declared_value > 20000;
    }

    public function getDeliveryDurationMinutesAttribute(): ?int
    {
        if (!$this->picked_up_at || !$this->delivered_at) {
            return null;
        }
        return (int) $this->picked_up_at->diffInMinutes($this->delivered_at);
    }
}
