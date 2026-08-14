<?php

namespace App\AI\DataObjects;

use App\AI\Contracts\MealPhotoInterpreter;

/**
 * What a meal-photo interpretation saw (capture-flow Phase B; BUILD_PLAN §1b).
 * Produced by a {@see MealPhotoInterpreter} from a plate
 * photo plus the user's pantry candidates.
 *
 * The pantry is the GROUNDING (BUILD_PLAN §1b): matched components are chosen
 * strictly from the candidate list the caller supplied — recognise-and-select,
 * not open-world guessing. Anything visible but not in the pantry is reported
 * under `alsoSeen` so the user isn't gaslit about what's on their plate.
 *
 * This is a PROPOSAL: it prefills the compose/eating-out screens for the user
 * to confirm or correct. It never logs anything by itself.
 */
final class MealPhotoReading
{
    /**
     * @param  list<int>  $pantryItemIds  candidate ids the model matched.
     * @param  list<string>  $alsoSeen  visible foods not among the candidates.
     */
    public function __construct(
        public readonly ?string $dishName,
        public readonly array $pantryItemIds,
        public readonly array $alsoSeen,
        public readonly float $confidence,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $allowedIds  the candidate ids that were offered — any
     *                                 id the model invents outside them is dropped.
     */
    public static function fromArray(array $data, array $allowedIds): self
    {
        $ids = [];

        foreach ((array) ($data['pantry_item_ids'] ?? []) as $id) {
            if (is_numeric($id) && in_array((int) $id, $allowedIds, true) && ! in_array((int) $id, $ids, true)) {
                $ids[] = (int) $id;
            }
        }

        $alsoSeen = [];

        foreach ((array) ($data['also_seen'] ?? []) as $name) {
            if (is_string($name) && trim($name) !== '') {
                $alsoSeen[] = mb_substr(trim($name), 0, 60);
            }
        }

        $dish = $data['dish_name'] ?? null;
        $confidence = is_numeric($data['confidence'] ?? null) ? (float) $data['confidence'] : 0.0;

        return new self(
            dishName: is_string($dish) && trim($dish) !== '' ? mb_substr(trim($dish), 0, 120) : null,
            pantryItemIds: $ids,
            alsoSeen: array_slice($alsoSeen, 0, 8),
            confidence: max(0.0, min(1.0, $confidence)),
        );
    }

    public function sawAnything(): bool
    {
        return $this->dishName !== null || $this->pantryItemIds !== [] || $this->alsoSeen !== [];
    }
}
