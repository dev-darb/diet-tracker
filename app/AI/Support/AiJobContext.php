<?php

namespace App\AI\Support;

use App\Services\AiJobLogger;

/**
 * Mutable scratch-pad passed into the closure wrapped by {@see AiJobLogger}.
 *
 * The AI call fills in whatever diagnostics it learns as it runs — the provider
 * and model it used, token usage, self-reported confidence, and a task-specific
 * result status. The logger reads these back after the call to write the
 * `ai_jobs` row (BUILD_PLAN idea #4; brief §11, §14). Latency, success/failure
 * and retry count are managed by the logger itself, not here.
 */
final class AiJobContext
{
    public ?string $provider = null;

    public ?string $model = null;

    public ?int $inputTokens = null;

    public ?int $outputTokens = null;

    public ?float $cost = null;

    public ?float $confidence = null;

    public ?string $resultStatus = null;

    /** Retries the call performed internally before succeeding/failing. */
    public int $retries = 0;
}
