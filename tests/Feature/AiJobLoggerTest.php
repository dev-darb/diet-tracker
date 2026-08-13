<?php

namespace Tests\Feature;

use App\AI\Support\AiJobContext;
use App\Models\AiJob;
use App\Services\AiJobLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AiJobLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_logs_a_success_row_and_returns_the_result(): void
    {
        $logger = new AiJobLogger;

        $result = $logger->run('product_identification', function (AiJobContext $ctx) {
            $ctx->provider = 'openrouter';
            $ctx->model = 'openai/gpt-4o-mini';
            $ctx->inputTokens = 100;
            $ctx->outputTokens = 20;
            $ctx->confidence = 0.9;
            $ctx->resultStatus = 'identified';

            return 'the-result';
        });

        $this->assertSame('the-result', $result);
        $this->assertDatabaseCount('ai_jobs', 1);

        $job = AiJob::first();
        $this->assertSame('product_identification', $job->task_type);
        $this->assertSame('openrouter', $job->provider);
        $this->assertSame('success', $job->status);
        $this->assertSame('identified', $job->result_status);
        $this->assertSame(0, $job->retries);
        $this->assertNotNull($job->latency_ms);
    }

    public function test_it_logs_a_failure_row_and_rethrows(): void
    {
        $logger = new AiJobLogger;

        try {
            $logger->run('product_identification', function (AiJobContext $ctx) {
                $ctx->provider = 'openrouter';
                $ctx->model = 'openai/gpt-4o-mini';

                throw new RuntimeException('provider exploded');
            });
            $this->fail('Expected the exception to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('provider exploded', $e->getMessage());
        }

        $this->assertDatabaseCount('ai_jobs', 1);

        $job = AiJob::first();
        $this->assertSame('failure', $job->status);
        $this->assertSame('error', $job->result_status);
        $this->assertNotNull($job->latency_ms);
    }

    public function test_it_defaults_provider_and_model_when_unset(): void
    {
        (new AiJobLogger)->run('product_identification', fn (AiJobContext $ctx) => null);

        $job = AiJob::first();
        $this->assertSame('unknown', $job->provider);
        $this->assertSame('unknown', $job->model);
    }
}
