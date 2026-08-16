<?php

namespace App\AI\OpenRouter;

use App\AI\Contracts\NutritionEstimator;
use App\AI\Support\AiJobContext;
use App\Nutrition\Estimation\EstimateGuard;
use App\Nutrition\Estimation\EstimationDraft;
use App\Nutrition\Estimation\EstimationRequest;
use App\Nutrition\NutrientRegistry;
use App\Services\AiJobLogger;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Throwable;

/**
 * Prism-backed nutrition estimation for foods no source has figures for.
 *
 * The prompt asks for a REASONED estimate, not a number. The model is made to
 * work the way a dietitian would out loud — name a reference food, state the
 * portion it is assuming, build up from components — and the steps it produces
 * are kept. That is not decoration: an unexplained figure cannot be checked by
 * anyone afterwards, and {@see EstimateGuard} rejects
 * a draft that arrives without its working.
 *
 * The model is also told, plainly, that declining is a legitimate answer. Most
 * failures of a system like this come from a model that would rather produce
 * something than admit it does not know, so guessing is made the worse option
 * rather than merely discouraged.
 */
class PrismNutritionEstimator implements NutritionEstimator
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

    public function estimate(EstimationRequest $request): ?EstimationDraft
    {
        try {
            return $this->logger->run('nutrition_estimation', function (AiJobContext $context) use ($request): EstimationDraft {
                $context->provider = $this->provider;
                $context->model = $this->model;

                $response = Prism::structured()
                    ->using($this->provider, $this->model)
                    ->withSchema($this->schema($request))
                    ->withSystemPrompt($this->systemPrompt())
                    ->withPrompt($this->userPrompt($request))
                    ->asStructured();

                $context->inputTokens = $response->usage->promptTokens;
                $context->outputTokens = $response->usage->completionTokens;

                $draft = EstimationDraft::fromArray($this->normalise($response->structured ?? []));

                $context->confidence = $draft->confidence;
                $context->resultStatus = $draft->values === [] ? 'declined' : 'drafted';

                return $draft;
            });
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * The model returns one figure per requested nutrient as a flat object, which
     * every provider handles more reliably than a free-form map. Fold it back
     * into the shape the draft expects.
     *
     * @param  array<string, mixed>  $structured
     * @return array<string, mixed>
     */
    private function normalise(array $structured): array
    {
        $values = [];

        foreach (NutrientRegistry::keys() as $key) {
            if (array_key_exists($key, $structured)) {
                $values[$key] = $structured[$key];
            }
        }

        return [
            'values' => $values,
            'steps' => $structured['steps'] ?? [],
            'assumptions' => $structured['assumptions'] ?? [],
            'reference' => $structured['reference'] ?? null,
            'confidence' => $structured['confidence'] ?? 0.0,
        ];
    }

    private function schema(EstimationRequest $request): ObjectSchema
    {
        $properties = [
            new ArraySchema(
                'steps',
                'Your reasoning, one short step per entry, in the order you worked it out. '
                .'Start from what the food is, then the portion, then the components you are adding up. '
                .'This is kept and may be shown to the user.',
                new StringSchema('step', 'One reasoning step.'),
            ),
            new ArraySchema(
                'assumptions',
                'Anything you had to assume — portion size, preparation, whether it is made with milk or water. One per entry.',
                new StringSchema('assumption', 'One assumption.'),
            ),
            new StringSchema('reference', 'What you based this on: a named reference food, a published menu figure, or a typical composition.'),
            new NumberSchema('confidence', 'Honest confidence 0.0-1.0. Use below 0.35 if you are really guessing — a low figure is respected, not penalised.'),
        ];

        $required = ['steps', 'assumptions', 'reference', 'confidence'];

        foreach ($request->nutrients as $key) {
            $nutrient = NutrientRegistry::get($key);

            if ($nutrient === null) {
                continue;
            }

            $properties[] = new NumberSchema(
                $key,
                sprintf(
                    '%s in %s, for %s. Null if you cannot reason about it for this food.',
                    $nutrient->label,
                    $nutrient->unit->label(),
                    $request->basis->label(),
                ),
                nullable: true,
            );
            $required[] = $key;
        }

        return new ObjectSchema(
            name: 'nutrition_estimate',
            description: 'A reasoned nutrition estimate for one food.',
            properties: $properties,
            requiredFields: $required,
        );
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        You estimate the nutrition of a food that is not in any nutrition database,
        so there is no label to read. You work like a dietitian thinking out loud.

        How to answer:
        - Show your working. Name a reference food or published figure, state the
          portion you are assuming, and build the estimate up from its components.
          The steps are kept and may be shown to the user, so write them for a
          person, not for a log.
        - State every assumption you made. Portion size, preparation method, whether
          a drink is made with milk or water — these change the answer more than the
          arithmetic does.
        - Give figures ONLY for the nutrients you were asked about, on the stated
          basis. Do not volunteer others.
        - Return null for any nutrient you cannot genuinely reason about for this
          food. Declining is a correct answer and costs you nothing.
        - Be honest about confidence. Below 0.35 means you are guessing; say so and
          the estimate will be discarded, which is the right outcome. Do not inflate
          a number to get an answer accepted.
        - Your figures must be internally consistent: energy should follow from the
          protein, carbohydrate and fat you state, sugars cannot exceed carbohydrate,
          and saturated fat cannot exceed total fat. A set that contradicts itself is
          rejected whole.
        PROMPT;
    }

    private function userPrompt(EstimationRequest $request): string
    {
        $nutrients = implode(', ', $request->nutrients);
        $context = $request->contextLines();

        $prompt = "Food: {$request->subject}\n"
            ."Estimate for: {$request->basis->label()}\n"
            ."Nutrients wanted: {$nutrients}";

        if ($context !== '') {
            $prompt .= "\n{$context}";
        }

        return $prompt;
    }
}
