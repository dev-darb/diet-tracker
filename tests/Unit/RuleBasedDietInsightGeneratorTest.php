<?php

namespace Tests\Unit;

use App\AI\DataObjects\DietInsightContext;
use App\AI\Local\RuleBasedDietInsightGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for the deterministic generator (BUILD_PLAN §6 J7.1). It takes
 * a structured context in and returns a phrased focus — no DB, no AI, no network.
 */
class RuleBasedDietInsightGeneratorTest extends TestCase
{
    private RuleBasedDietInsightGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new RuleBasedDietInsightGenerator;
    }

    /**
     * Build one indicator row in the NutritionAnalyticsService shape.
     */
    private function indicator(string $key, string $label, ?float $value, float $target, string $unit, string $direction, string $band): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'band' => $band,
            'value' => $value,
            'target' => $target,
            'unit' => $unit,
            'direction' => $direction,
            'known' => $value !== null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $indicators
     * @param  array<int, array<string, mixed>>  $pantry
     */
    private function context(array $indicators, array $pantry = [], int $loggedDays = 6, bool $hasData = true): DietInsightContext
    {
        return new DietInsightContext(
            userId: 1,
            periodStart: '2026-08-07',
            periodEnd: '2026-08-13',
            weekly: ['has_data' => $hasData, 'logged_days' => $loggedDays, 'indicators' => $indicators],
            pantry: $pantry,
            profile: ['goal' => 'eat_healthier', 'goal_label' => 'Eat healthier'],
        );
    }

    /** A full set of on-target indicators, so nothing scores as a gap. */
    private function balancedIndicators(): array
    {
        return [
            $this->indicator('protein', 'Protein', 60, 50, 'g', 'higher', 'Good'),
            $this->indicator('fibre', 'Fibre', 32, 30, 'g', 'higher', 'Good'),
            $this->indicator('fruit_veg', 'Fruit & veg', 5, 5, 'portions', 'higher', 'Good'),
            $this->indicator('saturated_fat', 'Saturated fat', 10, 20, 'g', 'lower', 'Good'),
            $this->indicator('salt', 'Salt', 4, 6, 'g', 'lower', 'Good'),
            $this->indicator('food_variety', 'Food variety', 20, 15, 'foods', 'higher', 'Good'),
        ];
    }

    public function test_it_picks_the_single_largest_higher_is_better_gap(): void
    {
        // Fibre is furthest below target (0.6 shortfall) vs fruit & veg (0.2).
        $indicators = $this->balancedIndicators();
        $indicators[1] = $this->indicator('fibre', 'Fibre', 12, 30, 'g', 'higher', 'Low');
        $indicators[2] = $this->indicator('fruit_veg', 'Fruit & veg', 4, 5, 'portions', 'higher', 'OK');

        $insight = $this->generator->generate($this->context($indicators));

        $this->assertSame('fibre', $insight->focusKey);
        $this->assertStringContainsStringIgnoringCase('fibre', $insight->title);
        $this->assertStringContainsString('12', $insight->body);
        $this->assertStringContainsString('30', $insight->body);
        $this->assertSame(RuleBasedDietInsightGenerator::PROVIDER, $insight->provider);
        // A Low band is a high-priority focus.
        $this->assertSame('high', $insight->priority);
    }

    public function test_it_goes_pantry_aware_when_a_matching_source_exists(): void
    {
        $indicators = $this->balancedIndicators();
        $indicators[1] = $this->indicator('fibre', 'Fibre', 12, 30, 'g', 'higher', 'Low');

        $pantry = [
            ['id' => 10, 'name' => 'Mornflake Oats', 'category' => 'cereals', 'per_100g' => ['fibre' => 9.0]],
            ['id' => 11, 'name' => 'Warburtons Wholemeal Wraps', 'category' => 'bakery', 'per_100g' => ['fibre' => 6.0]],
            ['id' => 12, 'name' => 'Coca-Cola', 'category' => 'drinks', 'per_100g' => ['fibre' => 0.0]],
        ];

        $insight = $this->generator->generate($this->context($indicators, $pantry));

        // Only the two real fibre sources are referenced, ordered by fibre desc.
        $this->assertSame([10, 11], $insight->pantryItemIds);
        $this->assertStringContainsString('Mornflake Oats', $insight->body);
        $this->assertStringContainsString('Warburtons Wholemeal Wraps', $insight->body);
        $this->assertStringNotContainsString('Coca-Cola', $insight->body);
        $this->assertStringContainsString('without buying anything', $insight->body);
    }

    public function test_a_low_fibre_snack_is_not_claimed_as_a_source(): void
    {
        $indicators = $this->balancedIndicators();
        $indicators[1] = $this->indicator('fibre', 'Fibre', 12, 30, 'g', 'higher', 'Low');

        // 1.5g/100g is below the 3g "source of fibre" threshold.
        $pantry = [['id' => 20, 'name' => 'White Bread', 'category' => 'bakery', 'per_100g' => ['fibre' => 1.5]]];

        $insight = $this->generator->generate($this->context($indicators, $pantry));

        $this->assertSame([], $insight->pantryItemIds);
        $this->assertStringNotContainsString('White Bread', $insight->body);
    }

    public function test_it_flags_an_over_target_cap(): void
    {
        $indicators = $this->balancedIndicators();
        $indicators[3] = $this->indicator('saturated_fat', 'Saturated fat', 30, 20, 'g', 'lower', 'Slightly high');

        $insight = $this->generator->generate($this->context($indicators));

        $this->assertSame('saturated_fat', $insight->focusKey);
        $this->assertStringContainsStringIgnoringCase('saturated fat', $insight->title);
        $this->assertStringContainsStringIgnoringCase('high', $insight->title);
        $this->assertStringContainsString('above the general', $insight->body);
        $this->assertSame([], $insight->pantryItemIds); // caps are not pantry-sourced
        $this->assertSame('high', $insight->priority);
    }

    public function test_it_does_not_overstate_with_sparse_data(): void
    {
        $indicators = $this->balancedIndicators();
        $indicators[1] = $this->indicator('fibre', 'Fibre', 12, 30, 'g', 'higher', 'Low');

        $insight = $this->generator->generate($this->context($indicators, [], loggedDays: 2));

        $this->assertStringContainsStringIgnoringCase('early signal', $insight->body);
    }

    public function test_it_gives_a_positive_when_everything_is_on_target(): void
    {
        $insight = $this->generator->generate($this->context($this->balancedIndicators()));

        $this->assertStringContainsStringIgnoringCase('well balanced', $insight->title);
        $this->assertSame([], $insight->pantryItemIds);
    }

    public function test_it_degrades_when_no_indicator_is_known(): void
    {
        $indicators = [
            $this->indicator('fibre', 'Fibre', null, 30, 'g', 'higher', 'Unknown'),
            $this->indicator('protein', 'Protein', null, 50, 'g', 'higher', 'Unknown'),
        ];

        $insight = $this->generator->generate($this->context($indicators));

        $this->assertNull($insight->focusKey);
        $this->assertStringContainsStringIgnoringCase('keep logging', $insight->title);
        $this->assertSame([], $insight->pantryItemIds);
    }
}
