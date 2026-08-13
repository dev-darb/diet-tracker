<?php

namespace App\Models;

use App\Enums\QuantityUnit;
use Database\Factories\PantryItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user's cached stock of one canonical product (BUILD_PLAN §5, idea #5).
 * `current_quantity` is maintained by PantryService; the transactions ledger is
 * the source of truth.
 */
class PantryItem extends Model
{
    /** @use HasFactory<PantryItemFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'canonical_product_id',
        'current_quantity',
        'quantity_unit',
        'purchased_at',
        'expiry_date',
    ];

    protected function casts(): array
    {
        return [
            'quantity_unit' => QuantityUnit::class,
            'current_quantity' => 'decimal:3',
            'purchased_at' => 'datetime',
            'expiry_date' => 'date',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<CanonicalProduct, $this> */
    public function canonicalProduct(): BelongsTo
    {
        return $this->belongsTo(CanonicalProduct::class);
    }

    /** @return HasMany<PantryTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(PantryTransaction::class);
    }
}
