<?php

namespace App\Services\FoodyScore;

use App\Models\FoodyMilestone;
use App\Models\FoodyScore;
use App\Models\User;

/**
 * Share payloads for the Foody Score (spec §18). The score is personal but
 * deliberately a social object: v1 ships a daily share card plus personal
 * milestones, and this payload is the stable contract later surfaces build
 * on (weekly cards, share images, private circles) without touching the
 * scoring model.
 *
 * DELIBERATE CONSTRAINTS, not omissions:
 *  - Everything in a payload is self-referential — the user's own score,
 *    streaks, bests. There is NO rank, NO leaderboard, NO other user, and
 *    payloads carry nothing that would let a consumer imply "87 beats 82":
 *    personalised targets and weights make cross-user ranking meaningless.
 *  - Future circles compare trends, consistency and milestones — shapes this
 *    payload already carries — never raw score vs raw score.
 */
class SharePayloadService
{
    /** Human labels for milestone kinds — personal achievements, no superlatives about others. */
    private const MILESTONE_LABELS = [
        'first_firm_score' => 'First firm score',
        'best_yet' => 'Personal best',
        'steady_streak_3' => '3 steady days in a row',
        'steady_streak_7' => 'A steady week',
        'steady_streak_14' => '14 steady days',
        'steady_streak_30' => '30 steady days',
    ];

    /**
     * The daily share payload for a recorded score.
     *
     * @return array{
     *     kind: string,
     *     date: string,
     *     score: int,
     *     state: string,
     *     band: string,
     *     milestones: array<int, array{kind: string, label: string}>,
     *     share_text: string,
     * }
     */
    public function daily(User $user, FoodyScore $record): array
    {
        $date = $record->score_date->toDateString();

        $milestones = FoodyMilestone::query()
            ->where('user_id', $user->id)
            ->whereDate('achieved_on', $date)
            // The daily close is a completion moment on Home, not a boast:
            // stamping every finished day onto the share card would dilute
            // the achievements that are genuinely distinctive.
            ->where('kind', '!=', 'day_closed')
            ->get()
            ->map(fn (FoodyMilestone $m) => [
                'kind' => $m->kind,
                'label' => $this->label($m->kind),
            ])
            ->values()
            ->all();

        return [
            'kind' => 'daily_score',
            'date' => $date,
            'score' => $record->score,
            'state' => $record->display_state,
            'band' => $record->band,
            'milestones' => $milestones,
            'share_text' => $this->shareText($record, $milestones),
        ];
    }

    public function label(string $kind): string
    {
        return self::MILESTONE_LABELS[$kind] ?? 'Milestone';
    }

    /**
     * The plain-text share line: the user's own day, nothing comparative.
     *
     * @param  array<int, array{kind: string, label: string}>  $milestones
     */
    private function shareText(FoodyScore $record, array $milestones): string
    {
        $text = "My Foody Score today: {$record->score}.";

        if ($milestones !== []) {
            $text .= ' '.$milestones[0]['label'].'.';
        }

        return $text;
    }
}
