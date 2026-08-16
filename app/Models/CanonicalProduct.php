<?php

namespace App\Models;

use App\Nutrition\MeasuredAmount;
use Database\Factories\CanonicalProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Shared canonical product identity (BUILD_PLAN §5). Relationships/casts only —
 * all domain logic lives in services.
 */
class CanonicalProduct extends Model
{
    /** @use HasFactory<CanonicalProductFactory> */
    use HasFactory;

    protected $fillable = [
        'gtin',
        'brand',
        'name',
        'raw_name',
        'display_name',
        'variant',
        'pack_size_value',
        'pack_size_unit',
        'category',
        'primary_image_path',
    ];

    protected function casts(): array
    {
        return [
            'pack_size_value' => 'decimal:3',
        ];
    }

    /**
     * Net pack contents as a real mass or volume, or null when the stored size
     * is not one (a legacy `1 "kg"` or `4 "x"` from the pre-audit parser reduces
     * to null rather than to one gram — audit D4/D5).
     */
    public function packSize(): ?MeasuredAmount
    {
        return MeasuredAmount::fromNumeric($this->pack_size_value, $this->pack_size_unit);
    }

    /**
     * What to call this product on screen.
     *
     * Falls back through brand + name for rows imported before clean names
     * existed, so nothing renders blank while the backfill catches up. Every
     * user-facing surface should read this rather than composing its own — the
     * app spent a while assembling `brand.' '.name` in eight different places,
     * which is how it ended up showing "Tesco TESCO FINEST…".
     */
    public function displayName(): string
    {
        if ($this->display_name !== null && $this->display_name !== '') {
            return $this->display_name;
        }

        return trim(($this->brand ?? '').' '.($this->name ?? '')) ?: 'Unnamed product';
    }

    /** @return HasMany<ProductVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ProductVersion::class);
    }

    /** @return HasMany<PantryItem, $this> */
    public function pantryItems(): HasMany
    {
        return $this->hasMany(PantryItem::class);
    }
}
