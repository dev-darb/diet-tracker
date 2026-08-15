<?php

namespace App\AI\Local;

use App\AI\Contracts\ScoreInsightWriter;
use App\AI\DataObjects\GeneratedInsight;
use App\Models\FoodyScore;

/**
 * Deterministic wording for score insight candidates (spec §14). No AI, no
 * network, no key: every candidate the engine can emit has a calm template
 * here, so a useful insight always ships and the LLM path always has an
 * honest seed it may only re-phrase.
 *
 * One Foody voice — the instrument speaks: plain statements of what the
 * numbers show and the single obvious next move. No personas, no cheerleading,
 * no food morality (nutrients and patterns, never "bad foods").
 */
class RuleBasedScoreInsightWriter implements ScoreInsightWriter
{
    public const PROVIDER = 'rule_based';

    public const MODEL = 'deterministic';

    public function word(FoodyScore $record, array $candidates): array
    {
        return array_map(fn (array $candidate) => $this->wordOne($record, $candidate), $candidates);
    }

    /** @param  array<string, mixed>  $candidate */
    private function wordOne(FoodyScore $record, array $candidate): GeneratedInsight
    {
        $data = $candidate['data'] ?? [];
        [$title, $body] = $this->template((string) $candidate['key'], $data);

        return new GeneratedInsight(
            insightType: GeneratedInsight::TYPE_SCORE_DAILY,
            title: $title,
            body: $body,
            priority: $this->priority($candidate),
            focusKey: (string) $candidate['key'],
            pantryItemIds: [],
            structuredInputs: [
                'candidate' => $candidate,
                'score' => $record->score,
                'band' => $record->band,
                'display_state' => $record->display_state,
                'algorithm_version' => $record->algorithm_version,
            ],
            provider: self::PROVIDER,
            model: self::MODEL,
        );
    }

    /**
     * The template per candidate key. Every figure comes from the candidate's
     * data payload — nothing is computed here (spec §2).
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: string}
     */
    private function template(string $key, array $data): array
    {
        return match ($key) {
            'protein_low' => [
                'Protein is behind today',
                sprintf('Around %sg so far against a %sg day. A protein-forward next meal closes most of the gap.',
                    $this->num($data['value'] ?? null), $this->num($data['target'] ?? null)),
            ],
            'carbs_low' => [
                'Carbs are running light',
                sprintf('Around %sg so far against a %sg day — worth fuelling properly at your next meal.',
                    $this->num($data['value'] ?? null), $this->num($data['target'] ?? null)),
            ],
            'fat_low' => [
                'Fat is running light',
                sprintf('Around %sg so far against a %sg day.',
                    $this->num($data['value'] ?? null), $this->num($data['target'] ?? null)),
            ],
            'energy_over' => [
                'Energy is running ahead of your day',
                sprintf('Roughly %s kcal logged against a %s kcal day. A lighter evening keeps the day on plan.',
                    $this->num($data['today_kcal'] ?? null), $this->num($data['target'] ?? null)),
            ],
            'fibre_low' => [
                'Fibre has room today',
                sprintf('Around %sg so far against a %sg day. Wholegrains, beans or fruit at the next meal move it quickly.',
                    $this->num($data['today'] ?? null), $this->num($data['target'] ?? null)),
            ],
            'plants_low' => [
                'Plant variety is narrow this week',
                sprintf('%s different plants so far this week. New additions count more than repeats.',
                    $this->num($data['unique_plants'] ?? null)),
            ],
            'plants_strong' => [
                'Plant variety is strong this week',
                sprintf('%s different plants this week — the range your gut does best on.',
                    $this->num($data['unique_plants'] ?? null)),
            ],
            'salt_high' => [
                'Salt is running high this week',
                sprintf('Averaging around %sg a day against the %sg guide. The pattern matters more than any single day.',
                    $this->num($data['rolling_avg'] ?? null), $this->num($data['target'] ?? null)),
            ],
            'saturated_fat_high' => [
                'Saturated fat is running high this week',
                sprintf('Averaging around %sg a day against the %sg guide.',
                    $this->num($data['rolling_avg'] ?? null), $this->num($data['target'] ?? null)),
            ],
            'free_sugars_high' => [
                'Sugars are running high this week',
                sprintf('Averaging around %sg a day against the %sg guide.',
                    $this->num($data['rolling_avg'] ?? null), $this->num($data['target'] ?? null)),
            ],
            default => [
                'Worth a look',
                'One of your tracked patterns has moved — the detail is in your day view.',
            ],
        };
    }

    /** @param  array<string, mixed>  $candidate */
    private function priority(array $candidate): string
    {
        $priority = (float) ($candidate['priority'] ?? 0.0);

        return match (true) {
            $priority >= 0.30 => GeneratedInsight::PRIORITY_HIGH,
            $priority >= 0.10 => GeneratedInsight::PRIORITY_MEDIUM,
            default => GeneratedInsight::PRIORITY_LOW,
        };
    }

    private function num(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '—';
        }

        return rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.');
    }
}
