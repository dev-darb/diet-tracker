<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A personal milestone (spec §17): first firm score, best-yet, streaks of
 * steady-or-better days. Personal progress only — milestones never compare
 * one user to another, and there are no public leaderboards.
 */
class FoodyMilestone extends Model
{
    protected $fillable = [
        'user_id',
        'kind',
        'achieved_on',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'achieved_on' => 'date:Y-m-d', // matches firstOrCreate's date-string lookup
            'payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
