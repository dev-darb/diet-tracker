<?php

namespace App\AI\OpenRouter;

use App\AI\Contracts\EatingOutEstimator;
use App\AI\DataObjects\EatingOutEstimate;
use App\AI\Support\AiJobContext;
use App\Services\AiJobLogger;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Throwable;

/**
 * Prism-backed {@see EatingOutEstimator} routed through the configured gateway
 * (BUILD_PLAN D2, §1b tier 3). Text-only: the dish name + venue go to the model
 * with a strict structured-output schema; known chains resolve against
 * published menu nutrition the model has seen, everything else against typical
 * composition. The model estimates FIGURES — it never does ledger arithmetic,
 * and the caller records the result marked `estimated` after user confirmation.
 *
 * Reliability contract: estimate() never throws into the request path — any
 * provider failure is reported and returns null, so the capture flow degrades
 * to manual entry (the same grace rule as every other AI capability).
 */
class PrismEatingOutEstimator implements EatingOutEstimator
{
    public function __construct(
        private readonly AiJobLogger $logger,
        private readonly string $provider,
        private readonly string $model,
    ) {}

    public function available(): bool
    {
        return true;
    }

    public function estimate(string $dish, ?string $venue = null): ?EatingOutEstimate
    {
        $dish = trim($dish);

        if ($dish === '') {
            return null;
        }

        try {
            return $this->logger->run('eating_out_estimation', function (AiJobContext $context) use ($dish, $venue): EatingOutEstimate {
                $context->provider = $this->provider;
                $context->model = $this->model;

                $response = Prism::structured()
                    ->using($this->provider, $this->model)
                    ->withSchema($this->schema())
                    ->withSystemPrompt($this->systemPrompt())
                    ->withPrompt($this->userPrompt($dish, $venue))
                    ->asStructured();

                $context->inputTokens = $response->usage->promptTokens;
                $context->outputTokens = $response->usage->completionTokens;

                $estimate = EatingOutEstimate::fromArray($response->structured ?? []);

                $context->confidence = $estimate->confidence;
                $context->resultStatus = $estimate->hasFigures() ? 'estimated' : 'no_estimate';

                return $estimate;
            });
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function schema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'eating_out_estimate',
            description: 'Estimated nutrition for one restaurant/cafe/takeaway dish as served.',
            properties: [
                new NumberSchema('calories', 'Estimated kcal for the full dish as served. Null only if truly unguessable.', nullable: true),
                new NumberSchema('protein', 'Estimated protein in grams. Null if unguessable.', nullable: true),
                new NumberSchema('carbs', 'Estimated carbohydrate in grams. Null if unguessable.', nullable: true),
                new NumberSchema('sugars', 'Estimated sugars in grams. Null if unguessable.', nullable: true),
                new NumberSchema('fat', 'Estimated fat in grams. Null if unguessable.', nullable: true),
                new NumberSchema('saturated_fat', 'Estimated saturated fat in grams. Null if unguessable.', nullable: true),
                new NumberSchema('fibre', 'Estimated fibre in grams. Null if unguessable.', nullable: true),
                new NumberSchema('salt', 'Estimated salt in grams. Null if unguessable.', nullable: true),
                new NumberSchema('confidence', 'Honest confidence 0.0-1.0: ~0.8+ published chain data, ~0.5 typical composition, lower when the dish is ambiguous.'),
                new StringSchema('basis', 'One short sentence stating what the estimate is based on, e.g. "Wagamama publishes chicken katsu curry at about 1180 kcal" or "Typical composition for a full English breakfast".'),
            ],
            requiredFields: ['calories', 'protein', 'carbs', 'sugars', 'fat', 'saturated_fat', 'fibre', 'salt', 'confidence', 'basis'],
        );
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        You estimate the nutrition of a single restaurant, cafe, or takeaway dish as served.

        Rules:
        - If the venue is a known chain that publishes nutrition (e.g. Wagamama, Nando's,
          McDonald's, Pret, Greggs), base the estimate on that published data for the named
          dish and say so in the basis.
        - Otherwise estimate from the typical composition and portion of the dish as commonly
          served in that kind of venue. State that the figures are typical values.
        - Figures are for the WHOLE dish as served, not per 100g.
        - Be honest: report confidence around 0.8+ only for published chain data, around 0.5
          for typical-composition estimates, lower when the dish name is ambiguous.
        - Use null for a figure only when it is genuinely unguessable; a reasonable typical
          estimate is more useful than null.
        - Never inflate precision — round to sensible whole-ish numbers.
        PROMPT;
    }

    private function userPrompt(string $dish, ?string $venue): string
    {
        $venue = $venue !== null && trim($venue) !== '' ? trim($venue) : null;

        return $venue === null
            ? "Estimate the nutrition of this dish: {$dish}"
            : "Estimate the nutrition of this dish: {$dish}. Eaten at: {$venue}.";
    }
}
