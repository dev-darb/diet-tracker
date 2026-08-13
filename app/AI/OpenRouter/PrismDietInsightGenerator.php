<?php

namespace App\AI\OpenRouter;

use App\AI\Contracts\DietInsightGenerator;
use App\AI\DataObjects\DietInsightContext;
use App\AI\DataObjects\GeneratedInsight;
use App\AI\Local\RuleBasedDietInsightGenerator;
use App\AI\Support\AiJobContext;
use App\Services\AiJobLogger;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

/**
 * Prism-backed {@see DietInsightGenerator} routed through OpenRouter (BUILD_PLAN
 * §6 J7.1, D2; brief §9.6–§9.9). It is the ONLY class here that touches Prism;
 * callers depend on the contract, so the model/gateway is swappable via
 * config/ai.php. Every call is wrapped by {@see AiJobLogger} so an `ai_jobs`
 * diagnostics row is written with latency, tokens and result status.
 *
 * ## The LLM only phrases — it computes nothing (brief §9.8/§9.9)
 * It receives the SAME deterministic {@see DietInsightContext} plus the
 * {@see RuleBasedDietInsightGenerator} pick as a seed, and returns a
 * prioritised, human-readable title + body. The deterministic `focusKey`,
 * `pantryItemIds` and `structuredInputs` are carried straight through from the
 * seed via {@see GeneratedInsight::withPhrasing()} — so the model can neither
 * invent a nutrient figure, choose a different gap, nor name a product the user
 * doesn't own. It is instructed to phrase the figures already in the context,
 * stay pantry-aware, and NOT overstate when data is incomplete (§9.7).
 */
class PrismDietInsightGenerator implements DietInsightGenerator
{
    public function __construct(
        private readonly AiJobLogger $logger,
        private readonly RuleBasedDietInsightGenerator $seedGenerator,
        private readonly string $provider,
        private readonly string $model,
    ) {}

    public function generate(DietInsightContext $context): GeneratedInsight
    {
        // The deterministic seed: the gap + pantry items + structured inputs the
        // LLM may only re-phrase, never override.
        $seed = $this->seedGenerator->generate($context);

        return $this->logger->run('diet_insight', function (AiJobContext $job) use ($context, $seed): GeneratedInsight {
            $job->provider = $this->provider;
            $job->model = $this->model;

            $response = Prism::structured()
                ->using($this->provider, $this->model)
                ->withSchema($this->schema())
                ->withSystemPrompt($this->systemPrompt())
                ->withPrompt($this->userPrompt($context, $seed))
                ->asStructured();

            $job->inputTokens = $response->usage->promptTokens;
            $job->outputTokens = $response->usage->completionTokens;

            $structured = $response->structured ?? [];

            $title = $this->text($structured['title'] ?? null) ?? $seed->title;
            $body = $this->text($structured['body'] ?? null) ?? $seed->body;
            $priority = $this->priority($structured['priority'] ?? null, $seed->priority);

            $job->resultStatus = 'insight_generated';

            return $seed->withPhrasing(
                title: $title,
                body: $body,
                priority: $priority,
                provider: $this->provider,
                model: $this->model,
            );
        });
    }

    /** Structured-output schema: a single prioritised observation (brief §9.6). */
    private function schema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'diet_insight',
            description: 'One prioritised, human-readable nutrition observation for the week.',
            properties: [
                new StringSchema('title', 'A short, calm headline for the focus, e.g. "Fibre is your biggest opportunity this week".'),
                new StringSchema('body', 'One or two sentences explaining the focus in plain language, pantry-aware where relevant. Phrase only the figures provided; never invent numbers or products.'),
                new EnumSchema('priority', 'How pressing this focus is.', ['high', 'medium', 'low']),
            ],
            requiredFields: ['title', 'body', 'priority'],
        );
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        You write ONE short, calm weekly nutrition observation for a general consumer.

        Hard rules:
        - Every number is already computed and given to you. Phrase those figures; NEVER invent, recompute, or estimate a nutrient value, average, or trend.
        - Only reference pantry items from the provided pantry list. Never name a food the user does not have.
        - Prefer the provided suggested focus and pantry items; you are rephrasing them more naturally, not choosing a different topic.
        - Do NOT overstate when data is incomplete — if few days are logged, hedge (e.g. "early signal").
        - This is general, non-medical guidance. No medical, diagnostic, or treatment claims.
        - Keep it to a title plus one or two sentences. No lists, no emojis.
        PROMPT;
    }

    private function userPrompt(DietInsightContext $context, GeneratedInsight $seed): string
    {
        $payload = [
            'suggested_focus' => [
                'metric' => $seed->focusKey,
                'title' => $seed->title,
                'body' => $seed->body,
                'priority' => $seed->priority,
                'pantry_item_ids' => $seed->pantryItemIds,
            ],
            'analytics_weekly_summary' => $context->weekly,
            'pantry' => $context->pantry,
            'profile' => $context->profile,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
        Here is the deterministic weekly analytics summary, the current pantry, the user's profile, and a suggested focus to phrase:

        {$json}

        Write the single most useful weekly observation as title, body and priority. Phrase the given figures; stay pantry-aware using only the listed items.
        PROMPT;
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function priority(mixed $value, string $fallback): string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($value, [
            GeneratedInsight::PRIORITY_HIGH,
            GeneratedInsight::PRIORITY_MEDIUM,
            GeneratedInsight::PRIORITY_LOW,
        ], true) ? $value : $fallback;
    }
}
