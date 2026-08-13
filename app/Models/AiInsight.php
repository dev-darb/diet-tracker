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
        'priority',
        'focus_key',
        'structured_inputs',
        'pantry_item_ids',
        'provider',
        'model',
        'dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'structured_inputs' => 'array',
            'pantry_item_ids' => 'array',
            'dismissed_at' => 'datetime',
        ];
    }

    /** Whether the user has dismissed this insight (hidden for its period). */
    public function isDismissed(): bool
    {
        return $this->dismissed_at !== null;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
