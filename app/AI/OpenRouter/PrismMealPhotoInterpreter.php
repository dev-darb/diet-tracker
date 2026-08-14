<?php

namespace App\AI\OpenRouter;

use App\AI\Contracts\MealPhotoInterpreter;
use App\AI\DataObjects\MealPhotoReading;
use App\AI\DataObjects\ProductImage;
use App\AI\Support\AiJobContext;
use App\Services\AiJobLogger;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Throwable;

/**
 * Prism-backed {@see MealPhotoInterpreter} through the env-selectable gateway
 * (BUILD_PLAN D2, §1b Phase B). Sends the plate photo (plus the user's pantry
 * candidate list, when grounding is wanted) to a multimodal model with a strict
 * structured-output schema.
 *
 * The model SELECTS pantry ids from the offered list and NAMES the dish — it
 * never estimates nutrition here (portions come from the user via chips;
 * eating-out figures come from EatingOutEstimator). Ids outside the candidate
 * list are dropped defensively in {@see MealPhotoReading::fromArray()}.
 *
 * Reliability contract: interpret() never throws into the request path — any
 * provider failure is reported and returns null so the capture flow degrades
 * to manual entry.
 */
class PrismMealPhotoInterpreter implements MealPhotoInterpreter
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

    public function interpret(ProductImage $photo, array $pantryCandidates = []): ?MealPhotoReading
    {
        $allowedIds = array_map(static fn (array $c): int => $c['id'], $pantryCandidates);

        try {
            return $this->logger->run('meal_photo_interpretation', function (AiJobContext $context) use ($photo, $pantryCandidates, $allowedIds): MealPhotoReading {
                $context->provider = $this->provider;
                $context->model = $this->model;

                $response = Prism::structured()
                    ->using($this->provider, $this->model)
                    ->withSchema($this->schema())
                    ->withSystemPrompt($this->systemPrompt())
                    ->withMessages([
                        new UserMessage($this->userPrompt($pantryCandidates), [$this->toPrismImage($photo)]),
                    ])
                    ->asStructured();

                $context->inputTokens = $response->usage->promptTokens;
                $context->outputTokens = $response->usage->completionTokens;

                $reading = MealPhotoReading::fromArray($response->structured ?? [], $allowedIds);

                $context->confidence = $reading->confidence;
                $context->resultStatus = $reading->sawAnything() ? 'interpreted' : 'nothing_seen';

                return $reading;
            });
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function schema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'meal_photo_reading',
            description: 'What is visible in this photo of a meal.',
            properties: [
                new StringSchema('dish_name', 'Short natural name for the dish, e.g. "Chicken katsu curry" or "Porridge with berries". Null if no food is visible.', nullable: true),
                new ArraySchema('pantry_item_ids', 'Ids of the CANDIDATE pantry items that visibly appear in this meal. Only ids from the provided list; empty if none or no list was provided.', new NumberSchema('id', 'A candidate pantry item id.')),
                new ArraySchema('also_seen', 'Foods clearly visible in the meal that are NOT among the candidates (short names, e.g. "white rice"). Empty if none.', new StringSchema('name', 'A visible food not in the candidate list.')),
                new NumberSchema('confidence', 'Honest confidence 0.0-1.0 in this reading overall.'),
            ],
            requiredFields: ['dish_name', 'pantry_item_ids', 'also_seen', 'confidence'],
        );
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        You read a photo of a meal someone is about to eat.

        Rules:
        - Name the dish naturally and concisely.
        - If a numbered list of the user's pantry items is provided, select ONLY the ids of
          items that visibly appear in the meal. Never select an id that is not in the list,
          and never select an item you cannot actually see evidence of.
        - List clearly visible foods that are NOT among the candidates under also_seen.
        - Do not estimate calories, weights, or any nutrition figures.
        - Report an honest overall confidence between 0 and 1.
        PROMPT;
    }

    /**
     * @param  list<array{id: int, label: string}>  $pantryCandidates
     */
    private function userPrompt(array $pantryCandidates): string
    {
        if ($pantryCandidates === []) {
            return 'Name the dish in this photo.';
        }

        $list = implode("\n", array_map(
            static fn (array $c): string => "- id {$c['id']}: {$c['label']}",
            $pantryCandidates,
        ));

        return "Read this meal photo. The user's pantry contains these candidate items:\n{$list}\n\nName the dish and select which candidate ids visibly appear in the meal.";
    }

    private function toPrismImage(ProductImage $image): Image
    {
        return match ($image->kind) {
            ProductImage::KIND_LOCAL_PATH => Image::fromLocalPath($image->value, $image->mimeType),
            ProductImage::KIND_STORAGE_PATH => Image::fromStoragePath($image->value, $image->disk),
            ProductImage::KIND_RAW => Image::fromRawContent($image->value, $image->mimeType),
            ProductImage::KIND_BASE64 => Image::fromBase64($image->value, $image->mimeType),
        };
    }
}
