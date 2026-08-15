<?php

namespace App\Services\FoodyScore;

use App\AI\Contracts\ScoreInsightWriter;
use App\AI\DataObjects\GeneratedInsight;
use App\AI\Local\RuleBasedScoreInsightWriter;
use App\Models\AiInsight;
use App\Models\FoodyScore;
use App\Models\User;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Daily score insights (spec §14): the deterministic engine has already
 * generated, reason-coded and RANKED the candidates persisted on the score
 * record. This service applies the display policy (at most 3, normally 1–2),
 * hands the survivors to a writer for wording in the one Foody voice, and
 * persists the result — the LLM can neither add a claim nor change the order.
 *
 * When the day genuinely has nothing worth saying, it says nothing: Home never
 * invents a health claim to fill the slot (spec acceptance).
 */
class ScoreInsightService
{
    /** Below this composite priority a candidate is noise, not an insight. */
    public const MIN_PRIORITY = 0.02;

    /**
     * A third insight must carry at least this share of the top candidate's
     * priority to earn the slot — "normally 1–2, max 3" (spec §14).
     */
    public const THIRD_SLOT_SHARE = 0.5;

    public function __construct(
        private readonly ScoreInsightWriter $writer,
        private readonly RuleBasedScoreInsightWriter $fallback,
    ) {}

    /**
     * The insights to show for this score record: cached rows if this record's
     * candidates were already worded, otherwise choose → word → persist.
     * Dismissed rows stay hidden. Empty when there is nothing worth saying.
     *
     * @return Collection<int, AiInsight>
     */
    public function insightsFor(User $user, FoodyScore $record): Collection
    {
        $chosen = $this->choose($record->candidates ?? []);

        if ($chosen === []) {
            return new Collection;
        }

        $date = $record->score_date->toDateString();
        $keys = array_column($chosen, 'key');

        $existing = $this->periodRows($user, $date)->get()->keyBy('focus_key');

        $missing = array_values(array_filter(
            $chosen,
            fn (array $c) => ! $existing->has($c['key']),
        ));

        if ($missing !== []) {
            foreach ($this->write($record, $missing) as $insight) {
                $row = $user->aiInsights()->updateOrCreate(
                    [
                        'insight_type' => GeneratedInsight::TYPE_SCORE_DAILY,
                        'period_start' => $date,
                        'focus_key' => $insight->focusKey,
                    ],
                    [
                        'period_end' => $date,
                        'title' => $insight->title,
                        'body' => $insight->body,
                        'priority' => $insight->priority,
                        'structured_inputs' => $insight->structuredInputs,
                        'pantry_item_ids' => [],
                        'provider' => $insight->provider,
                        'model' => $insight->model,
                    ],
                );
                $existing->put($row->focus_key, $row);
            }
        }

        // A candidate that resolved itself as the day evolved (protein caught
        // up by dinner) drops out; its undismissed row is cleared so stale
        // advice never lingers. Dismissed rows are kept as feedback.
        $this->periodRows($user, $date)
            ->whereNotIn('focus_key', $keys)
            ->whereNull('dismissed_at')
            ->delete();

        return collect($keys)
            ->map(fn (string $key) => $existing->get($key))
            ->filter(fn (?AiInsight $row) => $row !== null && ! $row->isDismissed())
            ->values();
    }

    /**
     * The display policy over the engine's ranked candidates: drop noise, take
     * the top two, and admit a third only when it genuinely competes.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>
     */
    public function choose(array $candidates): array
    {
        $eligible = array_values(array_filter(
            $candidates,
            fn (array $c) => (float) ($c['priority'] ?? 0.0) >= self::MIN_PRIORITY,
        ));

        if ($eligible === []) {
            return [];
        }

        $max = (int) config('foody_score.insights.max_shown');
        $chosen = array_slice($eligible, 0, min(2, $max));

        if ($max >= 3 && isset($eligible[2])) {
            $top = (float) $eligible[0]['priority'];
            if ($top > 0 && (float) $eligible[2]['priority'] >= self::THIRD_SLOT_SHARE * $top) {
                $chosen[] = $eligible[2];
            }
        }

        return $chosen;
    }

    /**
     * Word the candidates, degrading to deterministic templates if the AI
     * path throws — an insight always ships, never an error.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, GeneratedInsight>
     */
    private function write(FoodyScore $record, array $candidates): array
    {
        try {
            return $this->writer->word($record, $candidates);
        } catch (Throwable $e) {
            report($e);

            return $this->fallback->word($record, $candidates);
        }
    }

    private function periodRows(User $user, string $date)
    {
        return $user->aiInsights()
            ->where('insight_type', GeneratedInsight::TYPE_SCORE_DAILY)
            ->whereDate('period_start', $date);
    }
}
