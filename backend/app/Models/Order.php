<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read float $total_client
 */
class Order extends Model
{
    use HasFactory;

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

    protected $hidden = [
        'confirmation_code',
    ];

    protected $casts = [
        'rain_mode_applied' => 'boolean',
        'peak_hours_applied' => 'boolean',
        'confirmation_locked' => 'boolean',
        'accepted_at' => 'datetime',
        'ready_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    // Statuts possibles
    const STATUS_PENDING = 'pending';

    const STATUS_ACCEPTED = 'accepted';

    const STATUS_REFUSED = 'refused';

    const STATUS_READY = 'ready';

    const STATUS_PICKED_UP = 'picked_up';

    const STATUS_IN_TRANSIT = 'in_transit';

    const STATUS_DELIVERED = 'delivered';

    const STATUS_CANCELLED = 'cancelled';

    // ─── Relations ────────────────────────────────────────────────────────────

    /**
     * @return BelongsTo<User, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    /**
     * @return BelongsTo<Merchant, $this>
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * @return BelongsTo<Courier, $this>
     */
    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    /**
     * @return BelongsTo<City, $this>
     */
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
        if (! $this->picked_up_at || ! $this->delivered_at) {
            return null;
        }

        return (int) $this->picked_up_at->diffInMinutes($this->delivered_at);
    }

    public function getTotalClientAttribute(): float
    {
        return $this->products_total + $this->delivery_fee;
    }
}
