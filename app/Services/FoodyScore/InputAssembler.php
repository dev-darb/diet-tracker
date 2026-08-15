<?php

namespace App\Services\FoodyScore;

use App\Enums\PrimaryGoal;
use App\Models\ConsumptionEvent;
use App\Models\User;
use App\Services\NutritionTargetsService;
use App\ValueObjects\NutrientValues;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The engine's only doorway to the database. Everything with I/O or clock
 * dependence lives HERE; the ScoreEngine downstream is pure (spec §1). All
 * queries are anchored to an explicit $asOf so a test (or a backfill) can
 * assemble the exact input any past moment would have produced.
 *
 * Day totals reuse the event-level snapshot semantics the analytics service
 * established: sums come from the EVENT's snapshotted figures (the one place
 * pantry, home-cooked AND eating-out entries all carry their numbers), and
 * NutrientValues propagates unknowns so a day containing an unstated figure
 * reports that nutrient as unknown, never an understated 0 (spec §1).
 */
class InputAssembler
{
    /** Rolling evidence windows (spec §12). */
    public const DAY_WINDOW = 14;

    public const PLANT_WINDOW_DAYS = 7;

    /**
     * Category fragments that classify an item as a PLANT for the diversity
     * model (spec §9), grouped for the breadth bonus. Matched case-insensitively
     * against the canonical product's `category`. Superset of the analytics
     * service's fruit-&-veg keywords.
     *
     * @var array<string, array<int, string>>
     */
    public const PLANT_CATEGORY_GROUPS = [
        'fruit' => ['fruit', 'berries', 'berry'],
        'vegetables' => ['veg', 'vegetable', 'produce', 'salad', 'greens', 'tomato', 'potato', 'mushroom'],
        'legumes' => ['legume', 'bean', 'lentil', 'pulse', 'chickpea', 'pea'],
        'wholegrains' => ['grain', 'cereal', 'oat', 'rice', 'quinoa', 'barley', 'rye', 'wholemeal', 'whole wheat'],
        'nuts_seeds' => ['nut', 'seed', 'almond', 'walnut', 'cashew', 'peanut'],
    ];

    /**
     * Herbs and spices count towards flavour, not the plant benchmark
     * (spec §9 — they'd let a spice rack fake a diverse diet).
     *
     * @var array<int, string>
     */
    public const HERB_SPICE_KEYWORDS = ['herb', 'spice', 'seasoning', 'condiment'];

    /**
     * Fermented-food fragments for the small bounded gut-support bonus (§8).
     *
     * @var array<int, string>
     */
    public const FERMENTED_KEYWORDS = ['ferment', 'yogurt', 'yoghurt', 'kefir', 'kimchi', 'sauerkraut', 'kombucha', 'miso', 'tempeh'];

    public function __construct(
        private readonly NutritionTargetsService $targets,
    ) {}

    /**
     * Assemble the full deterministic input for one user at one moment.
     */
    public function assemble(User $user, CarbonImmutable $asOf, ?NutrientValues $scenario = null): ScoreInput
    {
        $asOf = $asOf->setTimezone(config('app.timezone'));
        $windowStart = $asOf->startOfDay()->subDays(self::DAY_WINDOW - 1);

        /** @var Collection<int, ConsumptionEvent> $events */
        $events = $user->consumptionEvents()
            ->with('items.canonicalProduct')
            ->whereBetween('consumed_at', [$windowStart, $asOf->endOfDay()])
            ->orderByDesc('consumed_at')
            ->get();

        $days = $this->dayTotals($events);
        $targets = $this->targets->targetsFor($user);

        return new ScoreInput(
            asOf: $asOf,
            profileKey: $user->profile?->primary_goal?->scoreProfile() ?? 'general_health',
            targets: $targets,
            macroMeta: $this->macroMeta($targets),
            days: $days,
            plants: $this->plantEvidence($events, $asOf),
            todayEventHours: $this->todayEventHours($events, $asOf),
            expectedFractionByNow: $this->expectedFractionByNow($events, $asOf),
            adequatelyLoggedDays: $this->adequatelyLoggedDays($days, $asOf),
            microDays: [], // audited: no reliable micronutrient columns yet (config core_set is empty)
            recentInsightKeys: $this->recentInsightKeys($user, $asOf),
            scenario: $scenario,
        );
    }

    /**
     * Per-day snapshot totals, newest first, keyed by ISO date. Only days with
     * logged consumption appear; unknown nutrients propagate honestly.
     *
     * @param  Collection<int, ConsumptionEvent>  $events
     * @return array<string, NutrientValues>
     */
    private function dayTotals(Collection $events): array
    {
        $days = [];

        foreach ($events as $event) {
            $date = $event->consumed_at->toDateString();
            $days[$date] = ($days[$date] ?? NutrientValues::zero())->add(
                NutrientValues::fromArray($event->only(NutrientValues::KEYS))
            );
        }

        return $days;
    }

    /**
     * Manual-target metadata per scored macro: whether the user set an explicit
     * target, and the profile-default figure it displaced (for the subweight
     * ratio rule, spec §4).
     *
     * @param  array<string, array{target: float, explicit?: bool, default?: float}>  $targets
     * @return array<string, array{explicit: bool, default: float}>
     */
    private function macroMeta(array $targets): array
    {
        $meta = [];

        foreach (['protein', 'carbs', 'fat'] as $macro) {
            $meta[$macro] = [
                'explicit' => (bool) ($targets[$macro]['explicit'] ?? false),
                'default' => (float) ($targets[$macro]['default'] ?? $targets[$macro]['target']),
            ];
        }

        return $meta;
    }

    /**
     * Item-level plant evidence over the last 7 days (spec §9): unique plants,
     * per-plant day counts (repeat consistency), category breadth, fermented
     * lines, fruit-&-veg lines, and the coverage counters that gate whether the
     * diversity components may claim anything at all.
     *
     * @param  Collection<int, ConsumptionEvent>  $events
     * @return array{unique_plants: int, plant_days: array<string, int>, classifiable: int, classified_plant: int, fermented: int, categories: array<int, string>, fruit_veg_lines: int, herb_spice_excluded: int}
     */
    private function plantEvidence(Collection $events, CarbonImmutable $asOf): array
    {
        $plantWindowStart = $asOf->startOfDay()->subDays(self::PLANT_WINDOW_DAYS - 1);

        $plantDayKeys = []; // product id => set of dates seen
        $classifiable = 0;
        $classifiedPlant = 0;
        $fermented = 0;
        $groups = [];
        $fruitVegLines = 0;
        $herbSpiceExcluded = 0;

        foreach ($events as $event) {
            if ($event->consumed_at->lessThan($plantWindowStart)) {
                continue;
            }
            $date = $event->consumed_at->toDateString();

            foreach ($event->items as $item) {
                $category = $item->canonicalProduct?->category;
                if ($category === null || $category === '') {
                    continue;
                }
                $classifiable++;
                $category = strtolower($category);

                if ($this->matchesAny($category, self::HERB_SPICE_KEYWORDS)) {
                    $herbSpiceExcluded++;

                    continue;
                }

                $group = $this->plantGroup($category);
                if ($group !== null) {
                    $classifiedPlant++;
                    $groups[$group] = true;

                    $plantDayKeys[(string) $item->canonical_product_id][$date] = true;

                    if (in_array($group, ['fruit', 'vegetables', 'legumes'], true)) {
                        $fruitVegLines++;
                    }
                }

                if ($this->matchesAny($category, self::FERMENTED_KEYWORDS)) {
                    $fermented++;
                }
            }
        }

        return [
            'unique_plants' => count($plantDayKeys),
            'plant_days' => array_map('count', $plantDayKeys),
            'classifiable' => $classifiable,
            'classified_plant' => $classifiedPlant,
            'fermented' => $fermented,
            'categories' => array_keys($groups),
            'fruit_veg_lines' => $fruitVegLines,
            'herb_spice_excluded' => $herbSpiceExcluded,
        ];
    }

    /**
     * @param  Collection<int, ConsumptionEvent>  $events
     * @return array<int, int>
     */
    private function todayEventHours(Collection $events, CarbonImmutable $asOf): array
    {
        $today = $asOf->toDateString();

        return $events
            ->filter(fn (ConsumptionEvent $e) => $e->consumed_at->toDateString() === $today)
            ->map(fn (ConsumptionEvent $e) => (int) $e->consumed_at->format('G'))
            ->values()
            ->all();
    }

    /**
     * The learned share of a day's intake expected by this time of day
     * (spec §12–§13). Learned from the user's own history: for each rolling day
     * with known per-event calories, the share of that day's calories logged at
     * or before asOf's time-of-day, averaged. Falls back to the default meal
     * schedule until enough history exists.
     *
     * @param  Collection<int, ConsumptionEvent>  $events
     */
    private function expectedFractionByNow(Collection $events, CarbonImmutable $asOf): float
    {
        $today = $asOf->toDateString();
        $secondsIntoDay = $asOf->secondsSinceMidnight();

        $fractions = [];
        $byDay = $events
            ->filter(fn (ConsumptionEvent $e) => $e->consumed_at->toDateString() !== $today)
            ->groupBy(fn (ConsumptionEvent $e) => $e->consumed_at->toDateString());

        foreach ($byDay as $dayEvents) {
            $total = 0.0;
            $byNow = 0.0;
            $known = true;

            foreach ($dayEvents as $event) {
                $calories = $event->calories === null ? null : (float) $event->calories;
                if ($calories === null) {
                    $known = false;
                    break;
                }
                $total += $calories;
                if ($event->consumed_at->secondsSinceMidnight() <= $secondsIntoDay) {
                    $byNow += $calories;
                }
            }

            if ($known && $total > 0) {
                $fractions[] = $byNow / $total;
            }
        }

        $minDays = (int) config('foody_score.confidence.learned_schedule_min_days');

        if (count($fractions) >= $minDays) {
            return round(array_sum($fractions) / count($fractions), 4);
        }

        // Fallback: the default meal schedule — the share of standard meal
        // slots that have passed by now (spec §13, before learning kicks in).
        $mealHours = config('foody_score.confidence.default_meal_hours');
        $passed = count(array_filter($mealHours, fn (int $h) => $asOf->hour >= $h));

        return round($passed / max(1, count($mealHours)), 4);
    }

    /**
     * Days in the 14-day window with usable evidence: at least one logged
     * event whose day total carries a known calorie figure.
     *
     * @param  array<string, NutrientValues>  $days
     */
    private function adequatelyLoggedDays(array $days, CarbonImmutable $asOf): int
    {
        $count = 0;

        foreach ($days as $date => $total) {
            if ($date === $asOf->toDateString()) {
                continue; // today is in progress, not yet historical evidence
            }
            if ($total->get('calories') !== null) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Insight focus keys surfaced within the novelty-suppression window, so the
     * engine can demote candidates the user has just seen (spec §14).
     *
     * @return array<int, string>
     */
    private function recentInsightKeys(User $user, CarbonImmutable $asOf): array
    {
        $windowStart = $asOf->subDays((int) config('foody_score.insights.novelty_suppression_days'));

        return $user->aiInsights()
            ->where('created_at', '>=', $windowStart)
            ->whereNotNull('focus_key')
            ->pluck('focus_key')
            ->unique()
            ->values()
            ->all();
    }

    private function matchesAny(string $haystack, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function plantGroup(string $category): ?string
    {
        foreach (self::PLANT_CATEGORY_GROUPS as $group => $keywords) {
            if ($this->matchesAny($category, $keywords)) {
                return $group;
            }
        }

        return null;
    }
}
