<?php

namespace App\Services;

use App\Models\ConsumptionEvent;
use App\Models\User;
use App\ValueObjects\NutrientValues;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Deterministic health analytics (BUILD_PLAN §6 J6.1; brief §9.3/§9.4/§9.8/§9.9).
 *
 * PURE-MATHS-ONLY by contract. The brief is explicit (§9.9) that the LLM does
 * NONE of the arithmetic here — daily/weekly aggregates, rolling averages, trend
 * percentages and the rule-based indicator thresholds are all computed in
 * deterministic PHP. This service is the single source of truth for those
 * figures; the structured summary it returns is exactly what the Milestone 7
 * DietInsightGenerator will hand to the LLM to explain/prioritise (§9.8).
 *
 * ## History is fixed (brief §10.3, idea #6)
 * Every figure is summed from the SNAPSHOTTED nutrient columns on
 * `consumption_items` — the values copied in at the moment eaten — never
 * recomputed from the current product version. A later reformulation therefore
 * cannot change a past day. Nutrient summation reuses {@see NutrientValues} so a
 * day whose total includes an unstated nutrient reports that nutrient as UNKNOWN
 * (null), never as an understated 0 (brief §2.1).
 *
 * ## Reference targets (brief §9.5)
 * Indicators map a value to a qualitative band (Good / OK / Low / Slightly high)
 * against GENERAL, NON-MEDICAL adult guidance — see {@see REFERENCE_TARGETS}. We
 * deliberately do NOT compute a single numeric "health score" (§9.5: it implies
 * false precision). All targets are documented constants, not magic numbers.
 */
class NutritionAnalyticsService
{
    public function __construct(private readonly NutritionTargetsService $targets) {}

    /** Window length for the "This week" horizon (brief §9.2 — Today + 7-day Week for MVP). */
    public const WEEK_DAYS = 7;

    /** Qualitative indicator bands (brief §9.5). Never a numeric score. */
    public const BAND_GOOD = 'Good';

    public const BAND_OK = 'OK';

    public const BAND_LOW = 'Low';

    public const BAND_SLIGHTLY_HIGH = 'Slightly high';

    public const BAND_UNKNOWN = 'Unknown';

    /**
     * General adult daily reference targets (brief §9.5 — component indicators).
     *
     * These are GENERAL, NON-MEDICAL guidance figures for a typical adult, used
     * only to colour a qualitative band. They are NOT personalised medical advice
     * (brief §9.10, BUILD_PLAN R4).
     *
     *  - protein 50 g/day   — EU/UK reference intake (RI) for protein.
     *  - fibre 30 g/day     — UK SACN / Eatwell recommendation (~30 g/day).
     *  - saturated fat 20 g — UK Eatwell reference (RI ~20 g/day; "less than").
     *  - salt 6 g/day       — UK NHS maximum for adults.
     *  - fruit & veg 5/day  — UK "5-a-day" portions.
     *
     * `direction`: `higher` = more is better (band Good/OK/Low); `lower` = a cap,
     * more is worse (band Good/OK/Slightly high).
     *
     * @var array<string, array{target: float, unit: string, direction: string, label: string}>
     */
    public const REFERENCE_TARGETS = [
        'protein' => ['target' => 50.0, 'unit' => 'g', 'direction' => 'higher', 'label' => 'Protein'],
        'fibre' => ['target' => 30.0, 'unit' => 'g', 'direction' => 'higher', 'label' => 'Fibre'],
        'fruit_veg' => ['target' => 5.0, 'unit' => 'portions', 'direction' => 'higher', 'label' => 'Fruit & veg'],
        'saturated_fat' => ['target' => 20.0, 'unit' => 'g', 'direction' => 'lower', 'label' => 'Saturated fat'],
        'salt' => ['target' => 6.0, 'unit' => 'g', 'direction' => 'lower', 'label' => 'Salt'],
    ];

    /** Higher-is-better: a value at ≥60% of target is "OK"; below is "Low". */
    public const OK_FLOOR_FRACTION = 0.6;

    /** Lower-is-better (a cap): up to 125% of the limit is "OK"; above is "Slightly high". */
    public const SLIGHTLY_HIGH_FRACTION = 1.25;

    /** Food-variety guidance: distinct foods we'd like to see (Today vs This-week windows). */
    public const FOOD_VARIETY_TARGET_DAILY = 4.0;

    public const FOOD_VARIETY_TARGET_WEEKLY = 15.0;

    /**
     * Category fragments that positively identify a fruit or vegetable, used to
     * derive fruit-&-veg portions. Matched case-insensitively against a product's
     * `category`. Fruit & veg is only reported when at least one consumed product
     * carries a classifiable (non-null) category — otherwise data quality is too
     * poor to claim a figure and it is marked unknown (brief §9.4/§9.7).
     *
     * @var array<int, string>
     */
    public const FRUIT_VEG_CATEGORY_KEYWORDS = ['fruit', 'veg', 'vegetable', 'produce', 'salad', 'greens', 'legume'];

    /** The nutrients we surface a qualitative indicator for (brief §9.3/§9.5). */
    public const INDICATOR_ORDER = ['protein', 'fibre', 'fruit_veg', 'saturated_fat', 'salt', 'food_variety'];

    /**
     * Today's snapshot for the Home view (brief §9.3): the day's totals, the
     * distinct-food count, and the qualitative component indicators.
     *
     * @return array{
     *     date: string,
     *     has_data: bool,
     *     totals: array<string, float|null>,
     *     unknown_nutrients: array<int, string>,
     *     food_variety: int,
     *     indicators: array<int, array<string, mixed>>,
     * }
     */
    public function dailySummary(User $user, ?Carbon $date = null): array
    {
        $day = CarbonImmutable::parse($date ?? now())->startOfDay();
        $targets = $this->targets->targetsFor($user);
        $days = $this->collectDays($user, $day, $day->endOfDay());
        $agg = $days[$day->toDateString()] ?? null;

        $total = $agg['total'] ?? null;
        $fruitVeg = $this->fruitVegPortions($agg, 1);

        $indicatorValues = [
            'protein' => $total?->get('protein'),
            'fibre' => $total?->get('fibre'),
            'saturated_fat' => $total?->get('saturated_fat'),
            'salt' => $total?->get('salt'),
            'fruit_veg' => $fruitVeg['known'] ? $fruitVeg['portions'] : null,
            'food_variety' => (float) ($agg['variety'] ?? 0),
        ];

        return [
            'date' => $day->toDateString(),
            'has_data' => $agg !== null,
            'totals' => ($total ?? NutrientValues::zero())->toArray(1),
            'unknown_nutrients' => $total?->unknownKeys() ?? [],
            'food_variety' => (int) ($agg['variety'] ?? 0),
            'indicators' => $this->buildIndicators($indicatorValues, self::FOOD_VARIETY_TARGET_DAILY, $targets),
            'targets' => $targets,
        ];
    }

    /**
     * Rolling 7-day summary for the Health view (brief §9.4/§9.8): per-metric
     * average/day, trend delta vs the previous 7 days, food variety, meal
     * regularity, fruit & veg, component indicators and per-day sparkline series.
     *
     * @return array{
     *     start: string,
     *     end: string,
     *     days: int,
     *     logged_days: int,
     *     has_data: bool,
     *     averages: array<string, array{value: float|null, known_days: int, partial: bool}>,
     *     trends: array<string, array{delta: float|null, direction: string, comparable: bool}>,
     *     food_variety: int,
     *     meal_regularity: array{days_logged: int, days: int, ratio: float},
     *     fruit_veg: array{known: bool, portions_per_day: float|null},
     *     indicators: array<int, array<string, mixed>>,
     *     sparklines: array<string, array<int, float|null>>,
     *     daily: array<int, array{date: string, has_data: bool, calories: float|null}>,
     * }
     */
    public function weeklySummary(User $user, ?Carbon $endDate = null): array
    {
        $end = CarbonImmutable::parse($endDate ?? now())->startOfDay();
        $targets = $this->targets->targetsFor($user);
        $currentStart = $end->subDays(self::WEEK_DAYS - 1);
        $previousStart = $currentStart->subDays(self::WEEK_DAYS);
        $previousEnd = $currentStart->subDay();

        // One fetch spanning both windows (current + previous week) for the trend.
        $all = $this->collectDays($user, $previousStart, $end->endOfDay());

        $currentDates = $this->dateRange($currentStart, $end);
        $previousDates = $this->dateRange($previousStart, $previousEnd);

        $averages = [];
        $trends = [];
        foreach (NutrientValues::KEYS as $key) {
            $current = $this->averageMetric($all, $currentDates, $key);
            $previous = $this->averageMetric($all, $previousDates, $key);

            $loggedDays = $this->loggedDayCount($all, $currentDates);
            $averages[$key] = [
                'value' => $current['value'],
                'known_days' => $current['known_days'],
                'partial' => $current['known_days'] > 0 && $current['known_days'] < $loggedDays,
            ];
            $trends[$key] = $this->trend($current['value'], $previous['value']);
        }

        $foodVariety = $this->distinctProducts($all, $currentDates);
        $loggedDays = $this->loggedDayCount($all, $currentDates);
        $fruitVeg = $this->fruitVegPortionsOverWindow($all, $currentDates);

        $indicatorValues = [
            'protein' => $averages['protein']['value'],
            'fibre' => $averages['fibre']['value'],
            'saturated_fat' => $averages['saturated_fat']['value'],
            'salt' => $averages['salt']['value'],
            'fruit_veg' => $fruitVeg['known'] ? $fruitVeg['portions_per_day'] : null,
            'food_variety' => (float) $foodVariety,
        ];

        return [
            'start' => $currentStart->toDateString(),
            'end' => $end->toDateString(),
            'days' => self::WEEK_DAYS,
            'logged_days' => $loggedDays,
            'has_data' => $loggedDays > 0,
            'averages' => $averages,
            'trends' => $trends,
            'food_variety' => $foodVariety,
            'meal_regularity' => [
                'days_logged' => $loggedDays,
                'days' => self::WEEK_DAYS,
                'ratio' => round($loggedDays / self::WEEK_DAYS, 3),
            ],
            'fruit_veg' => [
                'known' => $fruitVeg['known'],
                'portions_per_day' => $fruitVeg['known'] ? $fruitVeg['portions_per_day'] : null,
            ],
            'indicators' => $this->buildIndicators($indicatorValues, self::FOOD_VARIETY_TARGET_WEEKLY, $targets),
            'targets' => $targets,
            'sparklines' => [
                'calories' => $this->series($all, $currentDates, 'calories'),
                'protein' => $this->series($all, $currentDates, 'protein'),
                'fibre' => $this->series($all, $currentDates, 'fibre'),
            ],
            'daily' => array_map(fn (string $d) => [
                'date' => $d,
                'has_data' => isset($all[$d]),
                'calories' => isset($all[$d]) ? $all[$d]['total']->get('calories') : null,
            ], $currentDates),
        ];
    }

    /**
     * Aggregate each day's consumption in [$start, $end] into deterministic
     * snapshot totals + variety/fruit-veg counters. Only days with logged
     * consumption appear in the result.
     *
     * @return array<string, array{total: NutrientValues, variety: int, product_ids: array<int, int>, fruit_veg_lines: int, classifiable_lines: int}>
     */
    private function collectDays(User $user, CarbonImmutable $start, CarbonImmutable $end): array
    {
        /** @var Collection<int, ConsumptionEvent> $events */
        $events = $user->consumptionEvents()
            ->with('items.canonicalProduct')
            ->whereBetween('consumed_at', [$start, $end])
            ->get();

        $days = [];

        foreach ($events as $event) {
            $date = $event->consumed_at->toDateString();

            if (! isset($days[$date])) {
                $days[$date] = [
                    'total' => NutrientValues::zero(),
                    'product_ids' => [],
                    'fruit_veg_lines' => 0,
                    'classifiable_lines' => 0,
                ];
            }

            // Day totals come from the EVENT's snapshotted figures — the one
            // place every context carries its numbers: pantry singles and
            // home-cooked meals store the calculator's result there, and
            // eating-out entries (which deliberately have NO item rows) store
            // their estimates there. Summing items instead silently dropped
            // eating-out meals AND fabricated a 0-kcal day for them — both
            // violations of §1b/§2.1.
            $days[$date]['total'] = $days[$date]['total']->add(
                NutrientValues::fromArray($event->only(NutrientValues::KEYS))
            );

            // Items feed only the variety / fruit-&-veg counters.
            foreach ($event->items as $item) {
                if ($item->canonical_product_id !== null) {
                    $days[$date]['product_ids'][] = $item->canonical_product_id;
                }

                $category = $item->canonicalProduct?->category;
                if ($category !== null && $category !== '') {
                    $days[$date]['classifiable_lines']++;
                    if ($this->isFruitVegCategory($category)) {
                        $days[$date]['fruit_veg_lines']++;
                    }
                }
            }
        }

        foreach ($days as $date => $agg) {
            $days[$date]['variety'] = count(array_unique($agg['product_ids']));
        }

        return $days;
    }

    /**
     * Average of a nutrient over the given dates, honestly skipping days with no
     * data AND days whose total is unknown for that nutrient (brief §2.1) — a mean
     * must not silently absorb figures we never had.
     *
     * @param  array<string, array{total: NutrientValues}>  $days
     * @param  array<int, string>  $dates
     * @return array{value: float|null, known_days: int}
     */
    private function averageMetric(array $days, array $dates, string $key): array
    {
        $known = [];

        foreach ($dates as $date) {
            $value = isset($days[$date]) ? $days[$date]['total']->get($key) : null;
            if ($value !== null) {
                $known[] = $value;
            }
        }

        if ($known === []) {
            return ['value' => null, 'known_days' => 0];
        }

        return [
            'value' => round(array_sum($known) / count($known), $key === 'calories' ? 0 : 1),
            'known_days' => count($known),
        ];
    }

    /**
     * Signed trend fraction of current vs previous average (brief §9.8 —
     * e.g. -0.08 means 8% lower than the previous week). Only comparable when both
     * windows have a known average and the previous is non-zero.
     *
     * @return array{delta: float|null, direction: string, comparable: bool}
     */
    private function trend(?float $current, ?float $previous): array
    {
        if ($current === null || $previous === null || $previous == 0.0) {
            return ['delta' => null, 'direction' => 'flat', 'comparable' => false];
        }

        $delta = round(($current - $previous) / $previous, 3);

        return [
            'delta' => $delta,
            'direction' => $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat'),
            'comparable' => true,
        ];
    }

    /**
     * Map each indicator value to a qualitative band against its reference target
     * (brief §9.5). Food variety uses the supplied window target.
     *
     * @param  array<string, float|null>  $values
     * @param  array<string, array{target: float, unit: string, direction: string, label: string, basis: string, personalised: bool}>  $targets
     * @return array<int, array{key: string, label: string, band: string, value: float|null, target: float, unit: string, direction: string, known: bool, basis: string, personalised: bool}>
     */
    private function buildIndicators(array $values, float $foodVarietyTarget, array $targets): array
    {
        $indicators = [];

        foreach (self::INDICATOR_ORDER as $key) {
            if ($key === 'food_variety') {
                $meta = [
                    'target' => $foodVarietyTarget, 'unit' => 'foods', 'direction' => 'higher',
                    'label' => 'Food variety', 'basis' => 'App guidance: distinct foods over the window', 'personalised' => false,
                ];
            } else {
                // Per-user targets (NutritionTargetsService): personalised where
                // the profile allows, citable population guidance otherwise —
                // each carrying its receipt for the UI.
                $meta = $targets[$key];
            }

            $value = $values[$key] ?? null;

            $indicators[] = [
                'key' => $key,
                'label' => $meta['label'],
                'band' => $this->band($value, $meta['target'], $meta['direction']),
                'value' => $value,
                'target' => $meta['target'],
                'unit' => $meta['unit'],
                'direction' => $meta['direction'],
                'known' => $value !== null,
                'basis' => $meta['basis'],
                'personalised' => $meta['personalised'],
            ];
        }

        return $indicators;
    }

    /**
     * Rule-based banding (brief §9.9 — deterministic, no LLM). Boundaries are
     * INCLUSIVE at the target: hitting the target is Good.
     */
    private function band(?float $value, float $target, string $direction): string
    {
        if ($value === null) {
            return self::BAND_UNKNOWN;
        }

        if ($direction === 'higher') {
            return match (true) {
                $value >= $target => self::BAND_GOOD,
                $value >= $target * self::OK_FLOOR_FRACTION => self::BAND_OK,
                default => self::BAND_LOW,
            };
        }

        // Lower-is-better (a cap).
        return match (true) {
            $value <= $target => self::BAND_GOOD,
            $value <= $target * self::SLIGHTLY_HIGH_FRACTION => self::BAND_OK,
            default => self::BAND_SLIGHTLY_HIGH,
        };
    }

    /**
     * Fruit-&-veg portions for a single day's aggregate.
     *
     * @param  array{fruit_veg_lines?: int, classifiable_lines?: int}|null  $agg
     * @return array{known: bool, portions: float}
     */
    private function fruitVegPortions(?array $agg, int $days): array
    {
        $classifiable = $agg['classifiable_lines'] ?? 0;

        if ($classifiable === 0) {
            return ['known' => false, 'portions' => 0.0];
        }

        return [
            'known' => true,
            'portions' => round(($agg['fruit_veg_lines'] ?? 0) / $days, 1),
        ];
    }

    /**
     * Fruit-&-veg portions/day over a window. Known only when at least one
     * consumed product across the window carried a classifiable category.
     *
     * @param  array<string, array{fruit_veg_lines: int, classifiable_lines: int}>  $days
     * @param  array<int, string>  $dates
     * @return array{known: bool, portions_per_day: float}
     */
    private function fruitVegPortionsOverWindow(array $days, array $dates): array
    {
        $classifiable = 0;
        $fruitVeg = 0;

        foreach ($dates as $date) {
            if (! isset($days[$date])) {
                continue;
            }
            $classifiable += $days[$date]['classifiable_lines'];
            $fruitVeg += $days[$date]['fruit_veg_lines'];
        }

        if ($classifiable === 0) {
            return ['known' => false, 'portions_per_day' => 0.0];
        }

        return ['known' => true, 'portions_per_day' => round($fruitVeg / count($dates), 1)];
    }

    /**
     * Distinct canonical products consumed across a window (food variety).
     *
     * @param  array<string, array{product_ids: array<int, int>}>  $days
     * @param  array<int, string>  $dates
     */
    private function distinctProducts(array $days, array $dates): int
    {
        $ids = [];

        foreach ($dates as $date) {
            if (isset($days[$date])) {
                $ids = array_merge($ids, $days[$date]['product_ids']);
            }
        }

        return count(array_unique($ids));
    }

    /**
     * Per-day series for a sparkline: the metric's daily total, null on days with
     * no data or an unknown total (rendered as a gap, never a fabricated 0).
     *
     * @param  array<string, array{total: NutrientValues}>  $days
     * @param  array<int, string>  $dates
     * @return array<int, float|null>
     */
    private function series(array $days, array $dates, string $key): array
    {
        return array_map(function (string $date) use ($days, $key): ?float {
            $value = isset($days[$date]) ? $days[$date]['total']->get($key) : null;

            return $value === null ? null : round($value, $key === 'calories' ? 0 : 1);
        }, $dates);
    }

    /**
     * @param  array<string, mixed>  $days
     * @param  array<int, string>  $dates
     */
    private function loggedDayCount(array $days, array $dates): int
    {
        return count(array_filter($dates, fn (string $d) => isset($days[$d])));
    }

    private function isFruitVegCategory(string $category): bool
    {
        $category = strtolower($category);

        foreach (self::FRUIT_VEG_CATEGORY_KEYWORDS as $keyword) {
            if (str_contains($category, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The inclusive list of ISO dates from $start to $end.
     *
     * @return array<int, string>
     */
    private function dateRange(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $dates = [];
        for ($d = $start; $d->lessThanOrEqualTo($end); $d = $d->addDay()) {
            $dates[] = $d->toDateString();
        }

        return $dates;
    }
}
