<?php

namespace App\AI\OpenRouter;

use App\AI\Contracts\RecipeSuggester;
use App\AI\DataObjects\RecipeIdeas;
use App\AI\Support\AiJobContext;
use App\Models\User;
use App\Services\AiJobLogger;
use App\Services\NutritionTargetsService;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Throwable;

/**
 * Prism-backed {@see RecipeSuggester} through the env-selectable gateway. The
 * model receives the user's in-stock pantry (ids + labels + remaining
 * quantities), their goal + personalised targets, and their dietary
 * constraints — and returns a full day: breakfast, lunch, dinner and a snack,
 * each in the standardised recipe format (structured ingredients with amounts
 * and pantry grounding, upgrade suggestions, short steps).
 *
 * The model plans meals; it never touches the ledger or the deterministic
 * maths, and it NEVER suggests supplements or vitamins (founder call, Aug
 * 2026; brief §9.10). Failures report + return null.
 */
class PrismRecipeSuggester implements RecipeSuggester
{
    public function __construct(
        private readonly AiJobLogger $logger,
        private readonly NutritionTargetsService $targets,
        private readonly string $provider,
        private readonly string $model,
    ) {}

    public function available(): bool
    {
        return true;
    }

    public function suggest(User $user, array $pantryCandidates): ?RecipeIdeas
    {
        if ($pantryCandidates === []) {
            return null;
        }

        $allowedIds = array_map(static fn (array $c): int => $c['id'], $pantryCandidates);

        try {
            return $this->logger->run('recipe_suggestion', function (AiJobContext $context) use ($user, $pantryCandidates, $allowedIds): RecipeIdeas {
                $context->provider = $this->provider;
                $context->model = $this->model;

                $response = Prism::structured()
                    ->using($this->provider, $this->model)
                    ->withSchema($this->schema())
                    ->withSystemPrompt($this->systemPrompt())
                    ->withPrompt($this->userPrompt($user, $pantryCandidates))
                    ->asStructured();

                $context->inputTokens = $response->usage->promptTokens;
                $context->outputTokens = $response->usage->completionTokens;

                $ideas = RecipeIdeas::fromArray($response->structured ?? [], $allowedIds);

                $context->resultStatus = $ideas->hasSuggestions() ? 'suggested' : 'no_suggestions';

                return $ideas;
            });
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function schema(): ObjectSchema
    {
        $ingredient = new ObjectSchema(
            name: 'ingredient',
            description: 'One ingredient line of the recipe.',
            properties: [
                new StringSchema('name', 'Ingredient name, e.g. "Chicken thighs" or "Basmati rice".'),
                new StringSchema('amount', 'How much to use, e.g. "150 g", "1 tbsp", "half the pack".'),
                new NumberSchema('pantry_item_id', 'The candidate pantry item id this ingredient comes from, ONLY if it is in the provided list. Null for anything not in the pantry.', nullable: true),
            ],
            requiredFields: ['name', 'amount', 'pantry_item_id'],
        );

        $suggestion = new ObjectSchema(
            name: 'suggestion',
            description: 'One meal idea in the standardised recipe format.',
            properties: [
                new EnumSchema('slot', 'Which eating occasion this idea is for.', ['breakfast', 'lunch', 'dinner', 'snack']),
                new StringSchema('title', 'Short appetising dish name.'),
                new StringSchema('summary', 'One sentence on why this fits the user (stock, goal, speed).'),
                new ArraySchema('ingredients', 'The full ingredient list with amounts. Pantry items carry their id; everything else has pantry_item_id null.', $ingredient),
                new ArraySchema('upgrades', 'Up to 3 optional extras worth BUYING to make this dish even better (e.g. "fresh coriander lifts the curry"). Empty if none.', new StringSchema('upgrade', 'One optional extra and why.')),
                new ArraySchema('steps', 'The recipe as 3-8 short steps.', new StringSchema('step', 'One concise cooking step.')),
                new NumberSchema('approx_calories', 'Very rough kcal per serving. Null if unguessable.', nullable: true),
                new NumberSchema('approx_protein', 'Very rough protein grams per serving. Null if unguessable.', nullable: true),
            ],
            requiredFields: ['slot', 'title', 'summary', 'ingredients', 'upgrades', 'steps', 'approx_calories', 'approx_protein'],
        );

        return new ObjectSchema(
            name: 'recipe_ideas',
            description: "A full day of meal ideas from the user's pantry.",
            properties: [
                new ArraySchema('suggestions', 'Exactly four ideas: one breakfast, one lunch, one dinner, one snack.', $suggestion),
            ],
            requiredFields: ['suggestions'],
        );
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        You are a practical home chef planning today's eating from what the user actually has.

        Rules:
        - Suggest exactly four ideas: one breakfast, one lunch, one dinner, one snack.
        - Build each meal PRIMARILY from the numbered pantry list. In the ingredients list,
          set pantry_item_id ONLY for items from the list; every other ingredient (including
          staples like oil, salt, pepper) has pantry_item_id null so the user can see exactly
          what they'd need to get.
        - Give every ingredient a realistic amount, and never use more of a pantry item than
          the remaining quantity shown in the list.
        - upgrades are OPTIONAL nice-to-haves worth buying to lift the dish — short, concrete,
          with the why ("fresh coriander lifts the curry"). Never list an allergen there.
        - ABSOLUTE EXCLUSIONS: never use, suggest, or garnish with any listed allergen or
          avoided food — not even as an optional upgrade. Respect the dietary pattern strictly
          (e.g. vegan means no animal products anywhere).
        - Lean the day toward the user's goal and daily targets: protein-forward for
          muscle/recomposition goals, lighter for weight loss. The snack should be genuinely
          snack-sized.
        - Keep recipes genuinely simple: 3-8 short steps, everyday techniques.
        - approx figures are rough per-serving estimates only; use null when unguessable.
        - Never suggest, mention, or recommend supplements or vitamins in any form.
        PROMPT;
    }

    /**
     * @param  list<array{id: int, label: string, quantity: string}>  $pantryCandidates
     */
    private function userPrompt(User $user, array $pantryCandidates): string
    {
        $pantry = implode("\n", array_map(
            static fn (array $c): string => "- id {$c['id']}: {$c['label']} ({$c['quantity']} remaining)",
            $pantryCandidates,
        ));

        $profile = $user->profile;
        $targets = $this->targets->targetsFor($user);

        $lines = ["Today's pantry stock:\n{$pantry}", ''];
        $lines[] = 'About the user:';
        $lines[] = '- Goal: '.($profile?->primary_goal?->label() ?? 'not stated');
        $lines[] = '- Dietary pattern: '.($profile?->dietary_pattern?->label() ?? 'not stated');

        $list = static fn (?array $values): string => $values !== null && $values !== [] ? implode(', ', $values) : 'none';
        $lines[] = '- ALLERGIES (absolute exclusions): '.$list($profile?->allergies);
        $lines[] = '- Avoided foods (absolute exclusions): '.$list($profile?->avoided_foods);
        $lines[] = '- Preferences: '.$list($profile?->dietary_preferences);
        $lines[] = sprintf(
            '- Daily targets: ~%s kcal, ~%sg protein (%s)',
            number_format($targets['calories']['target']),
            number_format($targets['protein']['target']),
            $targets['calories']['personalised'] ? 'personalised' : 'general guidance',
        );
        $lines[] = '';
        $lines[] = 'Plan the day: breakfast, lunch, dinner and one snack.';

        return implode("\n", $lines);
    }
}
