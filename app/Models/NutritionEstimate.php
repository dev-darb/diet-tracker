<?php

namespace App\Models;

use App\Nutrition\Estimation\EstimationBasis;
use App\Nutrition\Estimation\EstimationReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The traceable record behind an estimated nutrition figure.
 *
 * See the create migration for why this exists. In short: a model may produce a
 * figure, but never anonymously — the reasoning that produced it is kept so the
 * question "where did this number come from" stays answerable months later, from
 * the record itself.
 */
class NutritionEstimate extends Model
{
    protected $fillable = [
        'user_id',
        'subject_type',
        'subject_id',
        'subject_label',
        'reason',
        'basis',
        'request',
        'steps',
        'assumptions',
        'reference',
        'values',
        'guard_notes',
        'accepted',
        'confidence',
        'provider',
        'model',
    ];

    protected function casts(): array
    {
        return [
            'reason' => EstimationReason::class,
            'basis' => EstimationBasis::class,
            'request' => 'array',
            'steps' => 'array',
            'assumptions' => 'array',
            'values' => 'array',
            'guard_notes' => 'array',
            'accepted' => 'boolean',
            'confidence' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** What this estimate ended up attached to, once it was committed. */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The working, as lines a person can read — the reasoning first, then what
     * had to be assumed to get there.
     *
     * @return array<int, string>
     */
    public function workingLines(): array
    {
        return [...($this->steps ?? []), ...($this->assumptions ?? [])];
    }
}
