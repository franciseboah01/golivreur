<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'wallet_id',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'reference',
        'description',
        'status',
        'order_id',
        'parcel_id',
        'processed_by',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'float',
        'balance_before' => 'float',
        'balance_after' => 'float',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (WalletTransaction $transaction) {
            throw new \RuntimeException('Les transactions de portefeuille sont strictement immuables et ne peuvent pas être modifiées.');
        });

        static::deleting(function (WalletTransaction $transaction) {
            throw new \RuntimeException('Les transactions de portefeuille sont strictement immuables et ne peuvent pas être supprimées.');
        });
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function parcel(): BelongsTo
    {
        return $this->belongsTo(Parcel::class);
    }
}
