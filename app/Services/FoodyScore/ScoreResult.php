<?php

namespace App\Services\FoodyScore;

/**
 * The deterministic engine's structured output (spec §2, §19): the score, the
 * pillar/component breakdown, the three confidence dimensions, reason codes,
 * contributors and candidate insights. This — never raw logs, never the
 * config — is what the AI layer is allowed to word.
 */
final class ScoreResult
{
    /**
     * @param  int  $score  0–100 raw engine output (pre-stability-smoothing)
     * @param  string  $band  internal semantic band key
     * @param  string  $displayState  firm | provisional | building
     * @param  array<string, array{score: ?float, weight: float, confidence: float, components: array<string, array{score: ?float, value: ?float, target: ?float, ratio: ?float}>}>  $pillars
     * @param  array{day_completeness: float, nutrient_coverage: float, historical: float}  $confidence
     * @param  array<int, string>  $reasonCodes
     * @param  array{up: array<int, string>, down: array<int, string>, largest_delta: ?string}  $contributors
     * @param  array<int, array{key: string, reason_code: string, kind: string, importance: float, confidence: float, actionability: float, timing_fit: float, novelty: float, priority: float, data: array<string, mixed>}>  $candidates
     */
    public function __construct(
        public readonly int $score,
        public readonly string $band,
        public readonly string $displayState,
        public readonly array $pillars,
        public readonly array $confidence,
        public readonly array $reasonCodes,
        public readonly array $contributors,
        public readonly array $candidates,
        public readonly string $algorithmVersion,
        public readonly string $targetRulesVersion,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'band' => $this->band,
            'display_state' => $this->displayState,
            'pillars' => $this->pillars,
            'confidence' => $this->confidence,
            'reason_codes' => $this->reasonCodes,
            'contributors' => $this->contributors,
            'candidates' => $this->candidates,
            'algorithm_version' => $this->algorithmVersion,
            'target_rules_version' => $this->targetRulesVersion,
        ];
    }
}
