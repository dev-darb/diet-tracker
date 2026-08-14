<?php

namespace App\AI\Contracts;

use App\AI\DataObjects\RecipeIdeas;
use App\Models\User;

/**
 * The AI chef (Pantry): breakfast / lunch / dinner ideas with simple recipes,
 * personalised to what the user ACTUALLY HAS in stock, their goal-shaped
 * nutrition targets, and their dietary constraints.
 *
 * Contract rules:
 *  - Pantry-grounded: recipes claim only supplied candidate ids as "from your
 *    pantry"; extras are declared under also_needed, never assumed.
 *  - Constraint-safe: the user's allergies and avoided foods are hard
 *    exclusions; dietary pattern (vegan, etc.) is always respected.
 *  - Advisory only: approximate figures are display estimates (~), never
 *    written to the ledger; logging the cooked meal goes through the normal
 *    compose flow, whose maths stay deterministic (brief §8.9).
 *  - suggest() returns null on failure/unavailability — the Pantry simply
 *    doesn't show the chef; nothing else degrades.
 */
interface RecipeSuggester
{
    /** Whether live suggestion is configured (an AI gateway key is present). */
    public function available(): bool;

    /**
     * @param  list<array{id: int, label: string, quantity: string}>  $pantryCandidates
     */
    public function suggest(User $user, array $pantryCandidates): ?RecipeIdeas;
}
