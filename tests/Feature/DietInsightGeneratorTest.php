<?php

namespace Tests\Feature;

use App\AI\Contracts\DietInsightGenerator;
use App\AI\DataObjects\DietInsightContext;
use App\AI\Local\RuleBasedDietInsightGenerator;
use App\AI\OpenRouter\PrismDietInsightGenerator;
use App\Models\AiJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Tests\TestCase;

class DietInsightGeneratorTest extends TestCase
{
    use RefreshDatabase;

    /** A minimal fibre-gap context with an oats pantry source. */
    private function fibreGapContext(): DietInsightContext
    {
        $indicator = fn (string $key, string $label, ?float $value, float $target, string $unit, string $direction, string $band) => [
            'key' => $key, 'label' => $label, 'band' => $band, 'value' => $value,
            'target' => $target, 'unit' => $unit, 'direction' => $direction, 'known' => $value !== null,
        ];

        return new DietInsightContext(
            userId: 1,
            periodStart: '2026-08-07',
            periodEnd: '2026-08-13',
            weekly: [
                'has_data' => true,
                'logged_days' => 6,
                'indicators' => [
                    $indicator('fibre', 'Fibre', 12, 30, 'g', 'higher', 'Low'),
                    $indicator('protein', 'Protein', 60, 50, 'g', 'higher', 'Good'),
                ],
            ],
            pantry: [['id' => 10, 'name' => 'Mornflake Oats', 'category' => 'cereals', 'per_100g' => ['fibre' => 9.0]]],
            profile: ['goal' => 'eat_healthier', 'goal_label' => 'Eat healthier'],
        );
    }

    private function fakeInsight(array $structured): void
    {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured($structured)
                ->withFinishReason(FinishReason::Stop)
                ->withUsage(new Usage(220, 60))
                ->withMeta(new Meta('fake-id', 'openai/gpt-4o')),
        ]);
    }

    public function test_key_absent_binds_the_rule_based_generator(): void
    {
        config()->set('prism.providers.openrouter.api_key', '');

        $this->assertInstanceOf(RuleBasedDietInsightGenerator::class, $this->app->make(DietInsightGenerator::class));
    }

    public function test_key_present_binds_the_prism_generator(): void
    {
        config()->set('prism.providers.openrouter.api_key', 'test-key');

        $this->assertInstanceOf(PrismDietInsightGenerator::class, $this->app->make(DietInsightGenerator::class));
    }

    public function test_prism_generator_maps_structured_output_and_keeps_deterministic_bones(): void
    {
        config()->set('prism.providers.openrouter.api_key', 'test-key');

        $this->fakeInsight([
            'title' => 'Fibre is worth a small nudge this week',
            'body' => 'You are around 12g/day versus the 30g guide, and your Mornflake Oats can help close it.',
            'priority' => 'high',
        ]);

        /** @var PrismDietInsightGenerator $generator */
        $generator = $this->app->make(PrismDietInsightGenerator::class);
        $insight = $generator->generate($this->fibreGapContext());

        // LLM phrasing is used...
        $this->assertSame('Fibre is worth a small nudge this week', $insight->title);
        $this->assertSame('high', $insight->priority);
        $this->assertSame('openrouter', $insight->provider);
        $this->assertSame('openai/gpt-4o', $insight->model);

        // ...but the deterministic focus + pantry references come from the seed.
        $this->assertSame('fibre', $insight->focusKey);
        $this->assertSame([10], $insight->pantryItemIds);
    }

    public function test_prism_generator_logs_an_ai_job(): void
    {
        config()->set('prism.providers.openrouter.api_key', 'test-key');

        $this->fakeInsight([
            'title' => 'Fibre focus',
            'body' => 'A little more fibre would help.',
            'priority' => 'medium',
        ]);

        $this->app->make(PrismDietInsightGenerator::class)->generate($this->fibreGapContext());

        $this->assertDatabaseCount('ai_jobs', 1);
        $job = AiJob::first();
        $this->assertSame('diet_insight', $job->task_type);
        $this->assertSame('openrouter', $job->provider);
        $this->assertSame('success', $job->status);
        $this->assertSame('insight_generated', $job->result_status);
        $this->assertSame(220, $job->input_tokens);
        $this->assertSame(60, $job->output_tokens);
    }
}
