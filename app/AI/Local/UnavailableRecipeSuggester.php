<?php

namespace App\AI\Local;

use App\AI\Contracts\RecipeSuggester;
use App\AI\DataObjects\RecipeIdeas;
use App\Models\User;

/**
 * KEY-ABSENT GRACE for the AI chef: with no gateway key the Pantry simply
 * doesn't offer meal suggestions — everything else is untouched.
 */
class UnavailableRecipeSuggester implements RecipeSuggester
{
    public function available(): bool
    {
        return false;
    }

    public function suggest(User $user, array $pantryCandidates): ?RecipeIdeas
    {
        return null;
    }
}
