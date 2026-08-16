<?php

namespace App\Models;

use App\Enums\QuantityUnit;
use App\Models\Concerns\HasNutrientColumns;
use Database\Factories\ConsumptionItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One component of a consumption event with SNAPSHOTTED nutrition (BUILD_PLAN §5,
 * idea #6). The snapshot means historical days never change if the product is
 * later reformulated — or if a later import corrects the product's figures.
 */
class ConsumptionItem extends Model
{
    /** @use HasFactory<ConsumptionItemFactory> */
    use HasFactory;

    use HasNutrientColumns;

    protected $fillable = [
        'consumption_event_id',
        'canonical_product_id',
        'product_version_id',
        'quantity',
        'unit',
        'portion_label',
    ];

    protected function casts(): array
    {
        return [
            'unit' => QuantityUnit::class,
            'quantity' => 'decimal:3',
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
