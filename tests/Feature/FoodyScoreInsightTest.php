<?php

namespace Tests\Feature;

use App\AI\DataObjects\GeneratedInsight;
use App\Models\AiInsight;
use App\Models\FoodyScore;
use App\Models\User;
use App\Services\FoodyScore\ScoreInsightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The score insight display pipeline (spec §14): engine-ranked candidates →
 * display policy (max 3, normally 1–2) → one-voice wording → persistence.
 * Runs keyless, so the deterministic writer is the bound implementation —
 * the same graceful path production takes with no AI key.
 */
class FoodyScoreInsightTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ScoreInsightService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->service = app(ScoreInsightService::class);
    }

    /** @param  array<int, array<string, mixed>>  $candidates */
    private function record(array $candidates, string $date = '2026-08-15'): FoodyScore
    {
        return FoodyScore::query()->create([
            'user_id' => $this->user->id,
            'score_date' => $date,
            'score' => 74, 'raw_score' => 74, 'band' => 'steady', 'display_state' => 'firm',
            'pillars' => [], 'reason_codes' => [], 'contributors' => [],
            'confidence' => ['day_completeness' => 0.8, 'nutrient_coverage' => 1, 'historical' => 1],
            'candidates' => $candidates,
            'algorithm_version' => 'foody_score_v1', 'target_rules_version' => 'target_rules_v1',
        ]);
    }

    /** @return array<string, mixed> */
    private function candidate(string $key, float $priority, array $data = []): array
    {
        return [
            'key' => $key,
            'reason_code' => "test.{$key}",
            'kind' => 'corrective',
            'importance' => 0.8, 'confidence' => 0.8, 'actionability' => 0.8,
            'timing_fit' => 0.8, 'novelty' => 1.0,
            'priority' => $priority,
            'data' => $data,
        ];
    }

    public function test_a_quiet_day_produces_no_insights_rather_than_filler(): void
    {
        $record = $this->record([]);

        $this->assertCount(0, $this->service->insightsFor($this->user, $record));
        $this->assertSame(0, AiInsight::query()->count());
    }

    public function test_noise_priority_candidates_are_not_worth_an_insight(): void
    {
        $record = $this->record([$this->candidate('fibre_low', 0.01)]);

        $this->assertCount(0, $this->service->insightsFor($this->user, $record));
    }

    public function test_normally_two_insights_show_even_when_more_candidates_exist(): void
    {
        // Third candidate well below half the top's priority → two shown.
        $record = $this->record([
            $this->candidate('protein_low', 0.40, ['value' => 42.0, 'target' => 120.0]),
            $this->candidate('fibre_low', 0.30, ['today' => 8.0, 'target' => 30.0]),
            $this->candidate('salt_high', 0.10, ['rolling_avg' => 7.5, 'target' => 6.0]),
        ]);

        $insights = $this->service->insightsFor($this->user, $record);

        $this->assertCount(2, $insights);
        $this->assertSame(['protein_low', 'fibre_low'], $insights->pluck('focus_key')->all());
    }

    public function test_a_competitive_third_candidate_earns_the_last_slot_never_a_fourth(): void
    {
        $record = $this->record([
            $this->candidate('protein_low', 0.40, ['value' => 42.0, 'target' => 120.0]),
            $this->candidate('fibre_low', 0.35, ['today' => 8.0, 'target' => 30.0]),
            $this->candidate('salt_high', 0.30, ['rolling_avg' => 7.5, 'target' => 6.0]),
            $this->candidate('energy_over', 0.28, ['today_kcal' => 2900.0, 'target' => 2200.0]),
        ]);

        $insights = $this->service->insightsFor($this->user, $record);

        $this->assertCount(3, $insights);
        $this->assertSame(['protein_low', 'fibre_low', 'salt_high'], $insights->pluck('focus_key')->all());
    }

    public function test_wording_phrases_the_engines_figures_in_one_voice(): void
    {
        $record = $this->record([
            $this->candidate('protein_low', 0.40, ['value' => 42.0, 'target' => 120.0]),
        ]);

        $insight = $this->service->insightsFor($this->user, $record)->first();

        $this->assertSame(GeneratedInsight::TYPE_SCORE_DAILY, $insight->insight_type);
        $this->assertStringContainsString('42', $insight->body);
        $this->assertStringContainsString('120', $insight->body);
        $this->assertStringNotContainsString('!', $insight->title.$insight->body);
        // The deterministic bones are stored with the wording (spec §19).
        $this->assertSame('protein_low', $insight->structured_inputs['candidate']['key']);
        $this->assertSame('foody_score_v1', $insight->structured_inputs['algorithm_version']);
    }

    public function test_a_resolved_candidate_clears_but_a_dismissed_one_stays_hidden(): void
    {
        $morning = $this->record([
            $this->candidate('protein_low', 0.40, ['value' => 20.0, 'target' => 120.0]),
            $this->candidate('fibre_low', 0.30, ['today' => 4.0, 'target' => 30.0]),
        ]);

        $insights = $this->service->insightsFor($this->user, $morning);
        $this->assertCount(2, $insights);

        // The user dismisses the fibre insight…
        $fibre = $insights->firstWhere('focus_key', 'fibre_low');
        $fibre->forceFill(['dismissed_at' => now()])->save();

        // …and by dinner protein has caught up, leaving only fibre_low live.
        $morning->update(['candidates' => [
            $this->candidate('fibre_low', 0.30, ['today' => 12.0, 'target' => 30.0]),
        ]]);
        $evening = $morning->fresh();

        $shown = $this->service->insightsFor($this->user, $evening);

        // Nothing shown: fibre stays dismissed, protein resolved and cleared.
        $this->assertCount(0, $shown);
        $this->assertNull(AiInsight::query()->where('focus_key', 'protein_low')->first());
        $this->assertNotNull(AiInsight::query()->where('focus_key', 'fibre_low')->whereNotNull('dismissed_at')->first());
    }

    public function test_repeat_calls_reuse_persisted_rows_without_rewording(): void
    {
        $record = $this->record([
            $this->candidate('protein_low', 0.40, ['value' => 42.0, 'target' => 120.0]),
        ]);

        $first = $this->service->insightsFor($this->user, $record)->first();
        $second = $this->service->insightsFor($this->user, $record)->first();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, AiInsight::query()->count());
    }
}
