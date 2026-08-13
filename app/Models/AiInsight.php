<?php

namespace App\Models;

use Database\Factories\AiInsightFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A generated nutrition insight for a period (BUILD_PLAN §5; brief §9.6, §12).
 * Ships in Milestone 7.
 */
class AiInsight extends Model
{
    /** @use HasFactory<AiInsightFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'insight_type',
        'period_start',
        'period_end',
        'title',
        'body',
        'structured_inputs',
        'provider',
        'model',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'structured_inputs' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
