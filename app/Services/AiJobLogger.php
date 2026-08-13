<?php

namespace App\Services;

use App\AI\Support\AiJobContext;
use App\Models\AiJob;
use Closure;
use Throwable;

/**
 * Wraps every AI capability call so exactly one `ai_jobs` diagnostics row is
 * written per call — the benchmarking backbone (BUILD_PLAN idea #4; brief §11,
 * §14). It measures latency, records success/failure (a thrown exception is a
 * failure and is re-thrown after logging), and reads provider/model/tokens/
 * confidence/result_status back from the {@see AiJobContext} the wrapped call
 * fills in.
 *
 * Capability implementations depend on THIS, not on the AiJob model directly, so
 * diagnostics logging is uniform and can never be forgotten by an individual
 * provider implementation.
 */
class AiJobLogger
{
    /**
     * Run an AI call, timing it and logging an `ai_jobs` row whether it
     * succeeds or throws.
     *
     * @template T
     *
     * @param  string  $taskType  e.g. "product_identification" (brief §14 task types).
     * @param  Closure(AiJobContext): T  $work  performs the call, mutating the
     *                                          context with the diagnostics it learns.
     * @return T the wrapped call's return value.
     *
     * @throws Throwable re-thrown after the failure row is persisted.
     */
    public function run(string $taskType, Closure $work): mixed
    {
        $context = new AiJobContext;
        $startedAt = microtime(true);

        try {
            $result = $work($context);
            $this->persist($taskType, $context, $startedAt, status: 'success');

            return $result;
        } catch (Throwable $e) {
            $context->resultStatus ??= 'error';
            $this->persist($taskType, $context, $startedAt, status: 'failure');

            throw $e;
        }
    }

    private function persist(string $taskType, AiJobContext $context, float $startedAt, string $status): void
    {
        AiJob::create([
            'task_type' => $taskType,
            'provider' => $context->provider ?? 'unknown',
            'model' => $context->model ?? 'unknown',
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'input_tokens' => $context->inputTokens,
            'output_tokens' => $context->outputTokens,
            'cost' => $context->cost,
            'status' => $status,
            'retries' => $context->retries,
            'confidence' => $context->confidence,
            'result_status' => $context->resultStatus,
        ]);
    }
}
