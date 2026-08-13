<?php

namespace App\Models;

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
