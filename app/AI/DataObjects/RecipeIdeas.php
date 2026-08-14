<?php

namespace App\AI\DataObjects;

use App\AI\Contracts\RecipeSuggester;

/**
 * AI-chef meal ideas for one day (breakfast / lunch / dinner), grounded in the
 * user's pantry. Produced by a {@see RecipeSuggester}.
 *
 * Grounding rules mirror the meal-photo reading: `pantry_item_ids` are chosen
 * strictly from the candidate list the caller supplied (invented ids are
 * dropped defensively); anything else the recipe needs is declared under
 * `also_needed` — never silently assumed to be in stock. Approximate figures
 * are rough per-serving estimates for display only, always shown with a tilde
 * and NEVER logged to the ledger (the deterministic maths rules, brief §8.9,
 * are untouched — logging a cooked meal still goes through the compose flow).
 */
final class RecipeIdeas
{
    public const SLOTS = ['breakfast', 'lunch', 'dinner'];

    /**
     * @param  list<array{slot: string, title: string, summary: string, pantry_item_ids: list<int>, also_needed: list<string>, steps: list<string>, approx_calories: float|null, approx_protein: float|null}>  $suggestions
     */
    public function __construct(public readonly array $suggestions) {}

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

            $ids = [];
            foreach ((array) ($raw['pantry_item_ids'] ?? []) as $id) {
                if (is_numeric($id) && in_array((int) $id, $allowedIds, true) && ! in_array((int) $id, $ids, true)) {
                    $ids[] = (int) $id;
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
                'pantry_item_ids' => $ids,
                'also_needed' => $strings($raw['also_needed'] ?? [], 6, 60),
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

        return new self($ordered);
    }

    public function hasSuggestions(): bool
    {
        return $this->suggestions !== [];
    }
}
