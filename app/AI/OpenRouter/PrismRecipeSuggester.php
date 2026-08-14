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
 * model receives the user's in-stock pantry (ids + labels + quantities), their
 * goal + personalised targets (with receipts), and their dietary constraints —
 * and returns one breakfast, one lunch and one dinner with short recipes.
 *
 * The model plans meals; it never touches the ledger or the deterministic
 * maths. Approximate figures come back as rough estimates and are displayed
 * with a tilde only. Failures report + return null so the Pantry quietly
 * shows no chef rather than an error.
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
        $suggestion = new ObjectSchema(
            name: 'suggestion',
            description: 'One meal idea with a short recipe.',
            properties: [
                new EnumSchema('slot', 'Which meal of the day this idea is for.', ['breakfast', 'lunch', 'dinner']),
                new StringSchema('title', 'Short appetising dish name, e.g. "Coconut chicken curry".'),
                new StringSchema('summary', 'One sentence on why this fits the user (stock, goal, speed).'),
                new ArraySchema('pantry_item_ids', 'Ids of the candidate pantry items this recipe uses. ONLY ids from the provided list.', new NumberSchema('id', 'A candidate pantry item id.')),
                new ArraySchema('also_needed', 'Ingredients the recipe needs that are NOT in the candidate list (short names, common staples like oil/salt included). Empty if none.', new StringSchema('name', 'An ingredient not in the pantry list.')),
                new ArraySchema('steps', 'The recipe as 3-8 short numbered-style steps.', new StringSchema('step', 'One concise cooking step.')),
                new NumberSchema('approx_calories', 'Very rough kcal per serving. Null if unguessable.', nullable: true),
                new NumberSchema('approx_protein', 'Very rough protein grams per serving. Null if unguessable.', nullable: true),
            ],
            requiredFields: ['slot', 'title', 'summary', 'pantry_item_ids', 'also_needed', 'steps', 'approx_calories', 'approx_protein'],
        );

        return new ObjectSchema(
            name: 'recipe_ideas',
            description: 'One breakfast, one lunch and one dinner idea from the pantry.',
            properties: [
                new ArraySchema('suggestions', 'Exactly three ideas: one breakfast, one lunch, one dinner.', $suggestion),
            ],
            requiredFields: ['suggestions'],
        );
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        You are a practical home chef planning today's meals from what the user actually has.

        Rules:
        - Suggest exactly three ideas: one breakfast, one lunch, one dinner.
        - Build each meal PRIMARILY from the numbered pantry list. Claim an item only by its
          id from the list; anything else the recipe needs goes in also_needed (common
          staples like oil, salt and pepper are fine there).
        - ABSOLUTE EXCLUSIONS: never use, suggest, or garnish with any listed allergen or
          avoided food — not even as an optional extra. Respect the dietary pattern strictly
          (e.g. vegan means no animal products anywhere).
        - Lean each meal toward the user's goal and daily targets (given below): e.g. protein
          -forward for muscle/recomposition goals, lighter for weight loss.
        - Keep recipes genuinely simple: 3-8 short steps, everyday techniques, no equipment
          most kitchens lack.
        - approx figures are rough per-serving estimates only; use null when unguessable.
        - Do not invent pantry quantities the list contradicts (e.g. don't build dinner
          around 500g of chicken when the list says 100g remains).
        PROMPT;
    }

    /**
     * @param  list<array{id: int, label: string, quantity: string}>  $pantryCandidates
     */
    private function userPrompt(User $user, array $pantryCandidates): string
    {
        $pantry = implode("\n", array_map(
            static fn (array $c): string => "- id {$c['id']}: {$c['label']} ({$c['quantity']})",
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
        $lines[] = 'Suggest one breakfast, one lunch and one dinner.';

        return implode("\n", $lines);
    }
}
