<?php

namespace App\Models;

use App\Enums\ProductVerificationStatus;
use App\Enums\ServingBasis;
use Database\Factories\ProductVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A dated set of nutrition figures for a canonical product (BUILD_PLAN §5).
 * Nutrient columns cast to decimal:2 to avoid float drift.
 */
class ProductVersion extends Model
{
    /** @use HasFactory<ProductVersionFactory> */
    use HasFactory;

    protected $fillable = [
        'canonical_product_id',
        'serving_basis',
        'serving_size_value',
        'serving_size_unit',
        'calories',
        'protein',
        'carbs',
        'sugars',
        'fat',
        'saturated_fat',
        'fibre',
        'salt',
        'ingredients',
        'allergens',
        'effective_from',
        'verified_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'serving_basis' => ServingBasis::class,
            'status' => ProductVerificationStatus::class,
            'serving_size_value' => 'decimal:3',
            'calories' => 'decimal:2',
            'protein' => 'decimal:2',
            'carbs' => 'decimal:2',
            'sugars' => 'decimal:2',
            'fat' => 'decimal:2',
            'saturated_fat' => 'decimal:2',
            'fibre' => 'decimal:2',
            'salt' => 'decimal:2',
            'allergens' => 'array',
            'effective_from' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CanonicalProduct, $this> */
    public function canonicalProduct(): BelongsTo
    {
        return $this->belongsTo(CanonicalProduct::class);
    }

    /** @return HasMany<ProductSource, $this> */
    public function sources(): HasMany
    {
        return $this->hasMany(ProductSource::class);
    }
}
