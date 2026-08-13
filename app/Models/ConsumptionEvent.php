<?php

namespace App\Models;

use App\Enums\ConsumptionType;
use Database\Factories\ConsumptionEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A logged eating event (BUILD_PLAN §5; brief §8.1/§8.4). Totals are
 * deterministic sums of its snapshotted items. Consumption logic ships in
 * Milestone 4.
 */
class ConsumptionEvent extends Model
{
    /** @use HasFactory<ConsumptionEventFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'name',
        'consumed_at',
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
            'type' => ConsumptionType::class,
            'consumed_at' => 'datetime',
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ConsumptionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ConsumptionItem::class);
    }

    /**
     * The pantry ledger rows this event is responsible for (the original
     * `consume` plus any `correction` written when the event was edited). Their
     * net delta is the event's live effect on stock — the basis for a correct
     * reversal (BUILD_PLAN §6 J4.1; brief §8.6/§8.7).
     *
     * @return HasMany<PantryTransaction, $this>
     */
    public function pantryTransactions(): HasMany
    {
        return $this->hasMany(PantryTransaction::class, 'linked_consumption_event_id');
    }
}
