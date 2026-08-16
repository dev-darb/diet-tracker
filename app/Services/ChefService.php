<?php

namespace App\Services;

use App\AI\Contracts\RecipeSuggester;
use App\Models\ChefSuggestion;
use App\Models\ConsumptionEvent;
use App\Models\PantryItem;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The resident chef (tranche 4, Aug 2026): a little chef living in the
 * Pantry who knows what's in stock and suggests the likeliest CURRENT meal.
 *
 * Time-of-day aware: the hour picks the slot (later, user-set meal times).
 * One generation covers the whole day — every returned slot persists as a
 * durable row — so the pantry face renders instantly from the artifact and
 * the model is consulted at most once per day per user (plus explicit
 * refreshes). Keyless or empty-pantry days simply have no chef; nothing else
 * degrades. Approximate figures are display-only (~), never ledger maths.
 */
class ChefService
{
    /**
     * Slot boundaries, aligned with the score engine's default meal schedule
     * (config foody_score.confidence.default_meal_hours = 8 / 13 / 19).
     */
    private const LUNCH_FROM_HOUR = 11;

    private const DINNER_FROM_HOUR = 16;

    public function __construct(private readonly RecipeSuggester $suggester) {}

    /** The meal the user is likeliest heading toward right now. */
    public function slotNow(?CarbonImmutable $now = null): string
    {
        $hour = ($now ?? CarbonImmutable::now())->hour;

        return match (true) {
            $hour < self::LUNCH_FROM_HOUR => 'breakfast',
            $hour < self::DINNER_FROM_HOUR => 'lunch',
            default => 'dinner',
        };
    }

    /** Today's suggestion for the current slot, if one exists. Read-only. */
    public function current(User $user, ?CarbonImmutable $now = null): ?ChefSuggestion
    {
        $now = $now ?? CarbonImmutable::now();

        return ChefSuggestion::query()
            ->where('user_id', $user->id)
            ->whereDate('suggested_on', $now->toDateString())
            ->where('slot', $this->slotNow($now))
            ->first();
    }

    /**
     * Generate today's plan and persist every slot the model returned.
     * Returns the current slot's row, or null when the chef has nothing to
     * work with (no key, empty pantry, model failure).
     */
    public function plan(User $user, ?CarbonImmutable $now = null): ?ChefSuggestion
    {
        $now = $now ?? CarbonImmutable::now();

        if (! $this->suggester->available()) {
            return null;
        }

        $candidates = $this->candidates($user);

        if ($candidates === []) {
            return null;
        }

        $ideas = $this->suggester->suggest($user, $candidates);

        if ($ideas === null || ! $ideas->hasSuggestions()) {
            return null;
        }

        $config = config('ai.recipe_suggester');

        foreach ($ideas->suggestions as $idea) {
            $existing = ChefSuggestion::query()
                ->where('user_id', $user->id)
                ->whereDate('suggested_on', $now->toDateString())
                ->where('slot', $idea['slot'])
                ->first();

            // A cooked suggestion is a record of the day, never overwritten.
            if ($existing?->isCooked()) {
                continue;
            }

            ChefSuggestion::query()->updateOrCreate(
                ['user_id' => $user->id, 'suggested_on' => $now->toDateString(), 'slot' => $idea['slot']],
                [
                    'title' => $idea['title'],
                    'summary' => $idea['summary'] !== '' ? $idea['summary'] : null,
                    'ingredients' => $idea['ingredients'],
                    'upgrades' => $idea['upgrades'],
                    'steps' => $idea['steps'],
                    'approx_calories' => $idea['approx_calories'],
                    'approx_protein' => $idea['approx_protein'],
                    'consumption_event_id' => null,
                    'provider' => $config['provider'] ?? null,
                    'model' => $config['model'] ?? null,
                ],
            );
        }

        return $this->current($user, $now);
    }

    /** "Another idea": regenerate the day (cooked slots stay untouched). */
    public function refresh(User $user, ?CarbonImmutable $now = null): ?ChefSuggestion
    {
        $now = $now ?? CarbonImmutable::now();

        ChefSuggestion::query()
            ->where('user_id', $user->id)
            ->whereDate('suggested_on', $now->toDateString())
            ->whereNull('consumption_event_id')
            ->delete();

        return $this->plan($user, $now);
    }

    /** Close the loop: the suggestion was cooked and logged as this event. */
    public function markCooked(ChefSuggestion $suggestion, ConsumptionEvent $event): void
    {
        if ($suggestion->consumption_event_id === null) {
            $suggestion->update(['consumption_event_id' => $event->id]);
        }
    }

    /**
     * @return list<array{id: int, label: string, quantity: string}>
     */
    private function candidates(User $user): array
    {
        return PantryItem::query()
            ->with('canonicalProduct')
            ->where('user_id', $user->id)
            ->where('current_quantity', '>', 0)
            ->get()
            ->map(fn (PantryItem $i) => [
                'id' => $i->id,
                'label' => trim(($i->canonicalProduct->brand ?? '').' '.$i->canonicalProduct->name),
                'quantity' => rtrim(rtrim(number_format((float) $i->current_quantity, 3, '.', ''), '0'), '.').' '.$i->quantity_unit->shortLabelFor((float) $i->current_quantity),
            ])
            ->values()
            ->all();
    }
}
