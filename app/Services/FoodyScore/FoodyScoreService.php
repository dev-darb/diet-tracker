<?php

namespace App\Services\FoodyScore;

use App\Models\FoodyMilestone;
use App\Models\FoodyScore;
use App\Models\User;
use App\ValueObjects\NutrientValues;
use Carbon\CarbonImmutable;

/**
 * Orchestrates the deterministic pipeline: assemble input → run the pure
 * engine → smooth for display stability → persist today's record → detect
 * personal milestones. Only TODAY's row is ever written; historical rows keep
 * the algorithm_version that produced them and are never silently
 * recalculated (spec §15, §19).
 */
class FoodyScoreService
{
    public function __construct(
        private readonly InputAssembler $assembler,
        private readonly ScoreEngine $engine,
    ) {}

    /**
     * Compute the score as of now (or an explicit moment) and record it on
     * today's row. Returns the persisted record — `score` is the displayed
     * (smoothed) value, `raw_score` the engine's unsmoothed output.
     */
    public function computeAndRecord(User $user, ?CarbonImmutable $asOf = null): FoodyScore
    {
        $asOf = $asOf ?? CarbonImmutable::now();
        $input = $this->assembler->assemble($user, $asOf);
        $result = $this->engine->calculate($input);

        $displayed = $this->smooth($user, $asOf, $result);

        $record = FoodyScore::query()->updateOrCreate(
            ['user_id' => $user->id, 'score_date' => $asOf->toDateString()],
            [
                'score' => $displayed,
                'raw_score' => $result->score,
                'band' => $this->bandFor($displayed),
                'display_state' => $result->displayState,
                'pillars' => $result->pillars,
                'reason_codes' => $result->reasonCodes,
                'contributors' => $result->contributors,
                'confidence' => $result->confidence,
                'candidates' => $result->candidates,
                'algorithm_version' => $result->algorithmVersion,
                'target_rules_version' => $result->targetRulesVersion,
            ],
        );

        $this->detectMilestones($user, $record);

        return $record;
    }

    /**
     * Projected score for a planned meal (spec §16): what today would look
     * like with this addition. Never persisted, never smoothed — it's a
     * what-if, not a record.
     *
     * @return array{result: ScoreResult, delta: int}
     */
    public function projected(User $user, NutrientValues $scenario, ?CarbonImmutable $asOf = null): array
    {
        $asOf = $asOf ?? CarbonImmutable::now();

        $baseline = $this->engine->calculate($this->assembler->assemble($user, $asOf));
        $projected = $this->engine->calculate($this->assembler->assemble($user, $asOf, $scenario));

        return [
            'result' => $projected,
            'delta' => $projected->score - $baseline->score,
        ];
    }

    /** The latest recorded score, if any — read-only, no recompute. */
    public function latest(User $user): ?FoodyScore
    {
        return FoodyScore::query()
            ->where('user_id', $user->id)
            ->orderByDesc('score_date')
            ->first();
    }

    /**
     * Evidence-sensitive display stability (spec §15): the displayed score
     * moves from the previous displayed score by at most a step that grows
     * with day-completeness confidence — a big raw swing on thin evidence
     * drifts; the same swing on a fully-logged day lands. Smoothing anchors to
     * the most recent record within the last week; after a long gap the raw
     * score stands on its own.
     */
    private function smooth(User $user, CarbonImmutable $asOf, ScoreResult $result): int
    {
        $previous = FoodyScore::query()
            ->where('user_id', $user->id)
            ->whereDate('score_date', '>=', $asOf->subDays(7)->toDateString())
            ->whereDate('score_date', '<=', $asOf->toDateString())
            ->orderByDesc('score_date')
            ->first();

        if ($previous === null) {
            return $result->score;
        }

        $cfg = config('foody_score.stability');
        $evidence = (float) ($result->confidence['day_completeness'] ?? 0.0);
        $maxStep = $cfg['max_step_low_evidence']
            + ($cfg['max_step_high_evidence'] - $cfg['max_step_low_evidence']) * min(1.0, max(0.0, $evidence));

        $delta = $result->score - $previous->score;

        if (abs($delta) <= $maxStep) {
            return $result->score;
        }

        return (int) round($previous->score + ($delta <=> 0) * $maxStep);
    }

    /** Map a displayed score onto the internal semantic bands (config). */
    private function bandFor(int $score): string
    {
        foreach (config('foody_score.bands') as $band) {
            if ($score >= $band['min']) {
                return $band['key'];
            }
        }

        return 'rebuilding';
    }

    /**
     * Personal milestones (spec §17): first firm score, personal bests, and
     * steady-or-better streaks. Strictly self-referential — never a
     * comparison with anyone else, never a leaderboard.
     */
    private function detectMilestones(User $user, FoodyScore $record): void
    {
        if ($record->display_state !== 'firm') {
            return; // milestones only mint on firm evidence — no confetti for guesses
        }

        $achievedOn = $record->score_date->toDateString();

        $hadFirmBefore = FoodyScore::query()
            ->where('user_id', $user->id)
            ->where('display_state', 'firm')
            ->whereDate('score_date', '<', $achievedOn)
            ->exists();

        if (! $hadFirmBefore) {
            $this->mint($user, 'first_firm_score', $achievedOn, ['score' => $record->score]);
        }

        $previousBest = FoodyScore::query()
            ->where('user_id', $user->id)
            ->where('display_state', 'firm')
            ->whereDate('score_date', '<', $achievedOn)
            ->max('score');

        if ($hadFirmBefore && $previousBest !== null && $record->score > (int) $previousBest) {
            $this->mint($user, 'best_yet', $achievedOn, [
                'score' => $record->score,
                'previous_best' => (int) $previousBest,
            ]);
        }

        $streak = $this->steadyStreak($user, $record);
        foreach ([3, 7, 14, 30] as $length) {
            if ($streak === $length) {
                $this->mint($user, "steady_streak_{$length}", $achievedOn, ['days' => $length]);
            }
        }
    }

    /** Consecutive days (ending today) scoring steady-or-better (≥70). */
    private function steadyStreak(User $user, FoodyScore $record): int
    {
        if ($record->score < 70) {
            return 0;
        }

        $recent = FoodyScore::query()
            ->where('user_id', $user->id)
            ->whereDate('score_date', '<=', $record->score_date->toDateString())
            ->orderByDesc('score_date')
            ->limit(31)
            ->get(['score_date', 'score']);

        $streak = 0;
        $expected = CarbonImmutable::parse($record->score_date->toDateString());

        foreach ($recent as $row) {
            if ($row->score_date->toDateString() !== $expected->toDateString() || $row->score < 70) {
                break;
            }
            $streak++;
            $expected = $expected->subDay();
        }

        return $streak;
    }

    /** @param  array<string, mixed>  $payload */
    private function mint(User $user, string $kind, string $achievedOn, array $payload): void
    {
        FoodyMilestone::query()->firstOrCreate(
            ['user_id' => $user->id, 'kind' => $kind, 'achieved_on' => $achievedOn],
            ['payload' => $payload],
        );
    }
}
