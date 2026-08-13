<?php

namespace App\Models;

use Database\Factories\AiJobFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Diagnostics row for one AI call (BUILD_PLAN §5, idea #4; brief §11, §14).
 * Populated from Milestone 2 onward.
 */
class AiJob extends Model
{
    /** @use HasFactory<AiJobFactory> */
    use HasFactory;

    protected $fillable = [
        'task_type',
        'provider',
        'model',
        'latency_ms',
        'input_tokens',
        'output_tokens',
        'cost',
        'status',
        'retries',
        'confidence',
        'result_status',
    ];

    protected function casts(): array
    {
        return [
            'latency_ms' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost' => 'decimal:6',
            'retries' => 'integer',
            'confidence' => 'decimal:3',
        ];
    }
}
