<?php

namespace App\Models;

use App\Enums\ProductVerificationStatus;
use App\Enums\ServingBasis;
use App\Models\Concerns\HasNutrientColumns;
use App\Nutrition\MeasuredAmount;
use Database\Factories\ProductVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A dated set of nutrition figures for a canonical product (BUILD_PLAN §5).
 * The nutrient columns and their decimal casts come from the registry via
 * {@see HasNutrientColumns}; decimal, never float, so figures never drift.
 */
class ProductVersion extends Model
{
    /** @use HasFactory<ProductVersionFactory> */
    use HasFactory;

    use HasNutrientColumns;

    protected $fillable = [
        'canonical_product_id',
        'serving_basis',
        'serving_size_value',
        'serving_size_unit',
        'ingredients',
        'allergens',
        'effective_from',
        'verified_at',
        'status',
        'nutrient_coverage',
        'sanity_findings',
    ];

    protected function casts(): array
    {
        return [
            'serving_basis' => ServingBasis::class,
            'status' => ProductVerificationStatus::class,
            'serving_size_value' => 'decimal:3',
            'allergens' => 'array',
            'sanity_findings' => 'array',
            'nutrient_coverage' => 'decimal:3',
            'effective_from' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * The stated serving as a real mass or volume, or null when the row does not
     * carry one we can use.
     *
     * Historical rows can hold sizes like `1 "portion"` or `1 "bar"` — the old
     * parser wrote those before the Aug 2026 audit. They are not masses, so they
     * reduce to null here rather than being multiplied as though they were grams
     * (audit D5). Unknown, honestly, beats wrong by a factor of a thousand.
     */
    public function servingSize(): ?MeasuredAmount
    {
        return MeasuredAmount::fromNumeric($this->serving_size_value, $this->serving_size_unit);
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
