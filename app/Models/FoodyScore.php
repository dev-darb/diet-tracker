<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One user-day's Foody Score record. `score` is the displayed (stability-
 * smoothed) value; `raw_score` is the engine's unsmoothed output. Historical
 * rows keep the algorithm_version that produced them and are never silently
 * recalculated — only today's row is ever updated (spec §15, §19).
 */
class FoodyScore extends Model
{
    protected $fillable = [
        'user_id',
        'score_date',
        'score',
        'raw_score',
        'band',
        'display_state',
        'pillars',
        'reason_codes',
        'contributors',
        'confidence',
        'candidates',
        'algorithm_version',
        'target_rules_version',
    ];

    protected function casts(): array
    {
        return [
            // Y-m-d storage so updateOrCreate's date-string lookup matches the
            // stored value exactly — one row per user-day, updated in place.
            'score_date' => 'date:Y-m-d',
            'score' => 'integer',
            'raw_score' => 'integer',
            'pillars' => 'array',
            'reason_codes' => 'array',
            'contributors' => 'array',
            'confidence' => 'array',
            'candidates' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
