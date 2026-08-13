<?php

namespace App\AI\Local;

use App\AI\Contracts\DietInsightGenerator;
use App\AI\DataObjects\DietInsightContext;
use App\AI\DataObjects\GeneratedInsight;
use App\AI\OpenRouter\PrismDietInsightGenerator;
use App\Services\NutritionAnalyticsService;

/**
 * DETERMINISTIC insight generator (BUILD_PLAN §6 J7.1; brief §9.6/§9.7/§9.9).
 *
 * No AI, no network, no key. It reads the deterministic weekly summary already
 * computed by {@see NutritionAnalyticsService}, picks the SINGLE most significant
 * gap or opportunity (the lowest higher-is-better indicator, or the most
 * over-target cap), and — when the pantry holds a relevant item — makes the
 * observation pantry-aware ("Fibre is your biggest gap; you already have oats and
 * spinach in your pantry"), exactly the brief's §9.7 differentiator.
 *
 * This is the DEFAULT fallback so the app always delivers a useful, honest
 * insight WITHOUT any API key (mirrors the M2 Scan key-absent grace). It also
 * produces the deterministic SEED the {@see PrismDietInsightGenerator}
 * re-phrases, so the LLM can never invent the gap or the pantry items.
 *
 * It NEVER computes a nutrient figure — every number comes straight from the
 * context (brief §9.9). It also never overstates: with sparse data it softens to
 * an "early signal", and it only claims a pantry item is a good source when that
 * item's stated per-100 figure actually clears the source threshold.
 */
class RuleBasedDietInsightGenerator implements DietInsightGenerator
{
    public const PROVIDER = 'rule_based';

    public const MODEL = 'deterministic';

    /**
     * Per-100(g/ml) "good source" thresholds used to decide whether a pantry
     * item is worth naming for a higher-is-better focus. Aligned with general UK
     * front-of-pack guidance (fibre "source" = 3 g/100 g). Caps (saturated fat,
     * salt) are not pantry-sourced, so they have no threshold here.
     *
     * @var array<string, float>
     */
    private const SOURCE_THRESHOLDS = [
        'protein' => 10.0,
        'fibre' => 3.0,
    ];

    /** Below this many logged days we soften language and never overstate a trend. */
    private const SPARSE_LOGGED_DAYS = 3;

    /**
     * The indicators eligible to be THE weekly focus, and their deterministic
     * tie-break order. Food variety is deliberately excluded: it is a useful
     * background indicator but not a calm, actionable single headline, and its
     * large nominal target would otherwise crowd out the nutrient gaps the brief
     * centres on (fibre / protein / veg — §9.6/§9.7).
     */
    private const FOCUS_PREFERENCE = ['fibre', 'protein', 'fruit_veg', 'saturated_fat', 'salt'];

    /** How many pantry items to name in a single observation (keep it calm). */
    private const MAX_PANTRY_NAMED = 3;

    public function generate(DietInsightContext $context): GeneratedInsight
    {
        $focus = $this->pickFocus($context);
        $sparse = (int) ($context->weekly['logged_days'] ?? 0) < self::SPARSE_LOGGED_DAYS;

        if ($focus === null) {
            // No confident gap to name (all indicators unknown, or no logged data).
            return $this->build(
                $context,
                title: 'Keep logging to unlock your focus',
                body: 'There isn\'t quite enough logged yet to point out a confident focus. A few more days of what you eat and this will highlight your biggest opportunity.',
                priority: GeneratedInsight::PRIORITY_LOW,
                focusKey: null,
                pantryItemIds: [],
            );
        }

        if ($focus['score'] <= 0.0) {
            // Everything is around target — a genuine, non-overstated positive.
            return $this->build(
                $context,
                title: 'Your week looks well balanced',
                body: $this->soften($sparse, 'Nothing stands out as a gap right now — your tracked indicators are all around their general targets. Keep it up.'),
                priority: GeneratedInsight::PRIORITY_LOW,
                focusKey: $focus['key'],
                pantryItemIds: [],
            );
        }

        $relevant = $this->relevantPantry($focus['key'], $context->pantry);
        [$title, $body, $priority] = $this->phrase($focus, $relevant, $sparse);

        return $this->build(
            $context,
            title: $title,
            body: $body,
            priority: $priority,
            focusKey: $focus['key'],
            pantryItemIds: array_map(static fn (array $e): int => (int) $e['id'], $relevant),
        );
    }

    /**
     * Score every KNOWN component indicator and return the most significant one.
     *
     *  - higher-is-better (protein, fibre, fruit & veg, food variety): score is
     *    the shortfall fraction (target − value)/target, positive when below target;
     *  - lower-is-better caps (saturated fat, salt): score is the excess fraction
     *    (value − target)/target, positive when over the cap.
     *
     * Returns the highest-scoring indicator (tie-broken by {@see FOCUS_PREFERENCE}),
     * or a zero-score indicator when everything is on target, or null when no
     * indicator is known at all.
     *
     * @return array{key: string, label: string, value: float, target: float, unit: string, direction: string, band: string, score: float}|null
     */
    private function pickFocus(DietInsightContext $context): ?array
    {
        /** @var array<int, array<string, mixed>> $indicators */
        $indicators = $context->weekly['indicators'] ?? [];

        $best = null;

        foreach ($indicators as $indicator) {
            if (! ($indicator['known'] ?? false) || $indicator['value'] === null) {
                continue;
            }

            if (! in_array($indicator['key'] ?? null, self::FOCUS_PREFERENCE, true)) {
                continue; // e.g. food variety — a background indicator, not a headline.
            }

            $value = (float) $indicator['value'];
            $target = (float) $indicator['target'];
            $direction = (string) $indicator['direction'];

            if ($target <= 0.0) {
                continue;
            }

            $score = $direction === 'higher'
                ? max(0.0, ($target - $value) / $target)
                : max(0.0, ($value - $target) / $target);

            $candidate = [
                'key' => (string) $indicator['key'],
                'label' => (string) $indicator['label'],
                'value' => $value,
                'target' => $target,
                'unit' => (string) $indicator['unit'],
                'direction' => $direction,
                'band' => (string) $indicator['band'],
                'score' => $score,
            ];

            if ($this->beats($candidate, $best)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * Whether $candidate should replace the current best: strictly higher score
     * wins; equal score is broken deterministically by {@see FOCUS_PREFERENCE}.
     *
     * @param  array{key: string, score: float}  $candidate
     * @param  array{key: string, score: float}|null  $best
     */
    private function beats(array $candidate, ?array $best): bool
    {
        if ($best === null) {
            return true;
        }

        if ($candidate['score'] > $best['score']) {
            return true;
        }

        if ($candidate['score'] < $best['score']) {
            return false;
        }

        return $this->preferenceRank($candidate['key']) < $this->preferenceRank($best['key']);
    }

    private function preferenceRank(string $key): int
    {
        $rank = array_search($key, self::FOCUS_PREFERENCE, true);

        return $rank === false ? count(self::FOCUS_PREFERENCE) : $rank;
    }

    /**
     * The pantry items worth naming for this focus, deterministically ordered.
     * For higher-is-better nutrients we require the item's stated per-100 figure
     * to clear the source threshold (so we never claim a low-fibre snack as a
     * fibre source). For fruit & veg we match the product category. Caps and
     * food variety don't name specific items.
     *
     * @param  array<int, array<string, mixed>>  $pantry
     * @return array<int, array<string, mixed>>
     */
    private function relevantPantry(string $focusKey, array $pantry): array
    {
        if (isset(self::SOURCE_THRESHOLDS[$focusKey])) {
            $threshold = self::SOURCE_THRESHOLDS[$focusKey];

            $matches = array_values(array_filter($pantry, static function (array $entry) use ($focusKey, $threshold): bool {
                $value = $entry['per_100g'][$focusKey] ?? null;

                return $value !== null && (float) $value >= $threshold;
            }));

            usort($matches, static fn (array $a, array $b): int => ($b['per_100g'][$focusKey] ?? 0) <=> ($a['per_100g'][$focusKey] ?? 0));

            return array_slice($matches, 0, self::MAX_PANTRY_NAMED);
        }

        if ($focusKey === 'fruit_veg') {
            $matches = array_values(array_filter($pantry, fn (array $entry): bool => $this->isFruitVeg($entry['category'] ?? null)));

            return array_slice($matches, 0, self::MAX_PANTRY_NAMED);
        }

        return [];
    }

    private function isFruitVeg(?string $category): bool
    {
        if ($category === null || $category === '') {
            return false;
        }

        $category = strtolower($category);
        foreach (NutritionAnalyticsService::FRUIT_VEG_CATEGORY_KEYWORDS as $keyword) {
            if (str_contains($category, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Phrase the chosen focus into a calm title + body + priority. Pantry-aware
     * when relevant items exist. Priority follows the qualitative band (a Low /
     * Slightly-high band is a high-priority focus; OK is medium).
     *
     * @param  array{key: string, label: string, value: float, target: float, unit: string, direction: string, band: string, score: float}  $focus
     * @param  array<int, array<string, mixed>>  $relevant
     * @return array{0: string, 1: string, 2: string}
     */
    private function phrase(array $focus, array $relevant, bool $sparse): array
    {
        $label = $focus['label'];
        $value = $this->number($focus['value']);
        $target = $this->number($focus['target']);
        $unit = $this->unitPhrase($focus['unit']);

        $priority = in_array($focus['band'], [
            NutritionAnalyticsService::BAND_LOW,
            NutritionAnalyticsService::BAND_SLIGHTLY_HIGH,
        ], true) ? GeneratedInsight::PRIORITY_HIGH : GeneratedInsight::PRIORITY_MEDIUM;

        if ($focus['direction'] === 'higher') {
            $title = strtolower($label).' is your biggest opportunity this week';
            $title = ucfirst($title);
            $body = "You're averaging around {$value}{$unit}/day, below the general {$target}{$unit} guide.";

            if ($relevant !== []) {
                $names = $this->nameList($relevant);
                $body .= " You already have {$names} in your pantry, so you can lift it without buying anything.";
            }
        } else {
            $title = ucfirst(strtolower($label)).' is running a little high';
            $body = "You're averaging around {$value}{$unit}/day, above the general {$target}{$unit} guide. Easing it down over the week is a simple win.";
        }

        return [$title, $this->soften($sparse, $body), $priority];
    }

    /**
     * Prepend an honest "early signal" qualifier when data is sparse so we never
     * overstate a conclusion from a couple of days (brief §9.7).
     */
    private function soften(bool $sparse, string $body): string
    {
        return $sparse ? 'Early signal from your first few days: '.lcfirst($body) : $body;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function nameList(array $entries): string
    {
        $names = array_map(static fn (array $e): string => (string) $e['name'], $entries);

        if (count($names) === 1) {
            return $names[0];
        }

        $last = array_pop($names);

        return implode(', ', $names).' and '.$last;
    }

    private function unitPhrase(string $unit): string
    {
        // Portions / foods read better with a leading space; grams stay tight ("19g").
        return in_array($unit, ['portions', 'foods'], true) ? ' '.$unit : $unit;
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }

    /**
     * @param  array<int, int>  $pantryItemIds
     */
    private function build(
        DietInsightContext $context,
        string $title,
        string $body,
        string $priority,
        ?string $focusKey,
        array $pantryItemIds,
    ): GeneratedInsight {
        return new GeneratedInsight(
            insightType: GeneratedInsight::TYPE_WEEKLY_FOCUS,
            title: $title,
            body: $body,
            priority: $priority,
            focusKey: $focusKey,
            pantryItemIds: $pantryItemIds,
            structuredInputs: $context->toArray(),
            provider: self::PROVIDER,
            model: self::MODEL,
        );
    }
}
