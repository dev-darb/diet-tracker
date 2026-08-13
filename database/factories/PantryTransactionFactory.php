<?php

namespace Database\Factories;

use App\Enums\PantryTransactionType;
use App\Enums\QuantityUnit;
use App\Models\PantryItem;
use App\Models\PantryTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PantryTransaction>
 */
class PantryTransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'pantry_item_id' => PantryItem::factory(),
            'type' => PantryTransactionType::Purchase,
            'quantity_delta' => 1,
            'unit' => QuantityUnit::Unit,
            'linked_consumption_event_id' => null,
            'occurred_at' => now(),
        ];
    }
}
