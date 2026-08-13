<?php

namespace App\Models;

use App\Enums\QuantityUnit;
use Database\Factories\ConsumptionItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One component of a consumption event with SNAPSHOTTED macros (BUILD_PLAN §5,
 * idea #6). The snapshot means historical days never change if the product is
 * later reformulated.
 */
class ConsumptionItem extends Model
{
    /** @use HasFactory<ConsumptionItemFactory> */
    use HasFactory;

    protected $fillable = [
        'consumption_event_id',
        'canonical_product_id',
        'product_version_id',
        'quantity',
        'unit',
        'calories',
        'protein',
        'carbs',
        'sugars',
        'fat',
        'saturated_fat',
        'fibre',
        'salt',
    ];

    protected function casts(): array
    {
        return [
            'unit' => QuantityUnit::class,
            'quantity' => 'decimal:3',
            'calories' => 'decimal:2',
            'protein' => 'decimal:2',
            'carbs' => 'decimal:2',
            'sugars' => 'decimal:2',
            'fat' => 'decimal:2',
            'saturated_fat' => 'decimal:2',
            'fibre' => 'decimal:2',
            'salt' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<ConsumptionEvent, $this> */
    public function consumptionEvent(): BelongsTo
    {
        return $this->belongsTo(ConsumptionEvent::class);
    }

    /** @return BelongsTo<CanonicalProduct, $this> */
    public function canonicalProduct(): BelongsTo
    {
        return $this->belongsTo(CanonicalProduct::class);
    }

    /** @return BelongsTo<ProductVersion, $this> */
    public function productVersion(): BelongsTo
    {
        return $this->belongsTo(ProductVersion::class);
    }
}
