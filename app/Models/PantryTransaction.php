<?php

namespace App\Models;

use App\Enums\PantryTransactionType;
use App\Enums\QuantityUnit;
use Database\Factories\PantryTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable row in the pantry ledger (BUILD_PLAN §5, idea #5). Rows are
 * append-only; PantryService never updates or deletes them.
 */
class PantryTransaction extends Model
{
    /** @use HasFactory<PantryTransactionFactory> */
    use HasFactory;

    protected $fillable = [
        'pantry_item_id',
        'type',
        'quantity_delta',
        'unit',
        'linked_consumption_event_id',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => PantryTransactionType::class,
            'unit' => QuantityUnit::class,
            'quantity_delta' => 'decimal:3',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PantryItem, $this> */
    public function pantryItem(): BelongsTo
    {
        return $this->belongsTo(PantryItem::class);
    }

    /** @return BelongsTo<ConsumptionEvent, $this> */
    public function consumptionEvent(): BelongsTo
    {
        return $this->belongsTo(ConsumptionEvent::class, 'linked_consumption_event_id');
    }
}
