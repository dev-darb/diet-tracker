<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One resident-chef suggestion: a dish for one user, one day, one meal slot,
 * grounded in the stock they actually held when it was generated. Figures are
 * display estimates (~) and never touch the ledger; logging the cooked meal
 * goes through the deterministic compose flow, which links back here.
 */
class ChefSuggestion extends Model
{
    protected $fillable = [
        'user_id',
        'suggested_on',
        'slot',
        'title',
        'summary',
        'ingredients',
        'upgrades',
        'steps',
        'approx_calories',
        'approx_protein',
        'consumption_event_id',
        'provider',
        'model',
    ];

    protected function casts(): array
    {
        return [
            'suggested_on' => 'date:Y-m-d',
            'ingredients' => 'array',
            'upgrades' => 'array',
            'steps' => 'array',
            'approx_calories' => 'float',
            'approx_protein' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function consumptionEvent(): BelongsTo
    {
        return $this->belongsTo(ConsumptionEvent::class);
    }

    public function isCooked(): bool
    {
        return $this->consumption_event_id !== null;
    }

    /**
     * The pantry-item ids of the in-stock ingredients — the grounding the
     * "Cooked this" bridge preselects.
     *
     * @return list<int>
     */
    public function inStockItemIds(): array
    {
        return array_values(array_filter(array_map(
            fn (array $ingredient) => $ingredient['pantry_item_id'] ?? null,
            $this->ingredients ?? [],
        )));
    }
}
