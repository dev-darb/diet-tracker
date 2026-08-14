<?php

namespace App\AI\DataObjects;

use App\AI\Contracts\RecipeSuggester;

/**
 * AI-chef meal ideas for one day — breakfast, lunch, dinner and a snack —
 * grounded in the user's pantry. Produced by a {@see RecipeSuggester}.
 *
 * Standardised recipe format: each idea carries a structured INGREDIENTS list
 * ({name, amount, pantry_item_id}) where a non-null pantry_item_id marks "you
 * already have this" (ids validated against the offered candidates — invented
 * ids are stripped to null, demoting the ingredient to shopping-list honesty),
 * plus `upgrades` — extra things worth buying to make the dish better.
 *
 * `wellnessNote` is a single OPTIONAL general-wellbeing line (UK-population
 * guidance flavour, food-first). It is general guidance, never medical advice,
 * and the UI must label it as such (brief §9.10).
 *
 * Approximate figures are rough per-serving estimates for display only (~),
 * never logged — the deterministic maths rules (brief §8.9) are untouched.
 */
final class RecipeIdeas
{
    public const SLOTS = ['breakfast', 'lunch', 'dinner', 'snack'];

    /**
     * @param  list<array{slot: string, title: string, summary: string, ingredients: list<array{name: string, amount: string, pantry_item_id: int|null}>, upgrades: list<string>, steps: list<string>, approx_calories: float|null, approx_protein: float|null}>  $suggestions
     */
    public function __construct(
        public readonly array $suggestions,
        public readonly ?string $wellnessNote = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $allowedIds
     */
    public static function fromArray(array $data, array $allowedIds): self
    {
        $suggestions = [];

        foreach ((array) ($data['suggestions'] ?? []) as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $slot = is_string($raw['slot'] ?? null) ? strtolower(trim($raw['slot'])) : '';
            $title = is_string($raw['title'] ?? null) ? mb_substr(trim($raw['title']), 0, 120) : '';

            if (! in_array($slot, self::SLOTS, true) || $title === '') {
                continue;
            }

            $ingredients = [];
            foreach ((array) ($raw['ingredients'] ?? []) as $entry) {
                if (! is_array($entry) || ! is_string($entry['name'] ?? null) || trim($entry['name']) === '') {
                    continue;
                }

                $id = $entry['pantry_item_id'] ?? null;
                $id = is_numeric($id) && in_array((int) $id, $allowedIds, true) ? (int) $id : null;

                $ingredients[] = [
                    'name' => mb_substr(trim($entry['name']), 0, 80),
                    'amount' => is_string($entry['amount'] ?? null) ? mb_substr(trim($entry['amount']), 0, 40) : '',
                    'pantry_item_id' => $id,
                ];

                if (count($ingredients) === 12) {
                    break;
                }
            }

            $strings = static function (mixed $value, int $max, int $len): array {
                $out = [];
                foreach ((array) $value as $entry) {
                    if (is_string($entry) && trim($entry) !== '') {
                        $out[] = mb_substr(trim($entry), 0, $len);
                    }
                }

                return array_slice($out, 0, $max);
            };

            $figure = static function (mixed $value): ?float {
                if ($value === null || ! is_numeric($value)) {
                    return null;
                }
                $value = (float) $value;

                return ($value < 0 || $value > 5000) ? null : round($value);
            };

            $suggestions[] = [
                'slot' => $slot,
                'title' => $title,
                'summary' => is_string($raw['summary'] ?? null) ? mb_substr(trim($raw['summary']), 0, 240) : '',
                'ingredients' => $ingredients,
                'upgrades' => $strings($raw['upgrades'] ?? [], 6, 80),
                'steps' => $strings($raw['steps'] ?? [], 8, 240),
                'approx_calories' => $figure($raw['approx_calories'] ?? null),
                'approx_protein' => $figure($raw['approx_protein'] ?? null),
            ];
        }

        // One idea per slot, in day order.
        $bySlot = [];
        foreach ($suggestions as $suggestion) {
            $bySlot[$suggestion['slot']] ??= $suggestion;
        }

        $ordered = [];
        foreach (self::SLOTS as $slot) {
            if (isset($bySlot[$slot])) {
                $ordered[] = $bySlot[$slot];
            }
        }

        $wellness = $data['wellness_note'] ?? null;

        return new self(
            $ordered,
            is_string($wellness) && trim($wellness) !== '' ? mb_substr(trim($wellness), 0, 280) : null,
        );
    }

    public function hasSuggestions(): bool
    {
        return $this->suggestions !== [];
    }
}
