<?php

/*
|--------------------------------------------------------------------------
| Gateway switch (env-selectable, D2)
|--------------------------------------------------------------------------
|
| A SINGLE switch, AI_GATEWAY, selects which OpenAI-style gateway ALL AI
| capabilities route through. Both gateways speak the same `creator/model`
| format (e.g. "openai/gpt-4o-mini"), so only the Prism provider key — and
| therefore the base URL + api key — changes:
|
|   openrouter (default) -> Prism `openrouter` provider, OPENROUTER_API_KEY
|   vercel               -> Prism `vercel` provider (Vercel AI Gateway,
|                           https://ai-gateway.vercel.sh/v1), AI_GATEWAY_API_KEY
|
| The Prism provider key each capability uses defaults to the selected
| gateway; per-capability AI_*_PROVIDER envs can still override individually.
| Domain code never sees any of this — it only reads config('ai.<cap>').
|
*/

$gateway = strtolower((string) env('AI_GATEWAY', 'openrouter')) === 'vercel'
    ? 'vercel'
    : 'openrouter';

return [

    /*
    |--------------------------------------------------------------------------
    | AI capability → provider/model mapping (BUILD_PLAN D2, §4.3; brief §14)
    |--------------------------------------------------------------------------
    |
    | Each capability interface resolves its provider + model from here, so a
    | model can be swapped for benchmarking (brief §14) by changing config/env
    | alone — domain code never mentions a model string. The provider name is a
    | Prism provider key (see config/prism.php); it defaults to the gateway
    | selected above (D2), which reaches many underlying models through one key.
    |
    | No API key is required for the app to boot or for the test suite to pass
    | (tests use Prism::fake()). A live call needs the selected gateway's key
    | (OPENROUTER_API_KEY or AI_GATEWAY_API_KEY).
    |
    */

    // The resolved gateway provider key (Prism provider name) for all AI work.
    'gateway' => $gateway,

    'product_identifier' => [
        'provider' => env('AI_PRODUCT_IDENTIFIER_PROVIDER', $gateway),
        'model' => env('AI_PRODUCT_IDENTIFIER_MODEL', 'openai/gpt-4o-mini'),
    ],

    // Scaffolded for later milestones (M3/M5/M7); not yet consumed.
    'product_researcher' => [
        'provider' => env('AI_PRODUCT_RESEARCHER_PROVIDER', $gateway),
        'model' => env('AI_PRODUCT_RESEARCHER_MODEL', 'openai/gpt-4o'),
    ],

    // Meal-photo interpretation (capture flow Phase B): plate photo + pantry
    // candidates -> dish name + matched components. Needs a VISION model.
    'meal_interpreter' => [
        'provider' => env('AI_MEAL_INTERPRETER_PROVIDER', $gateway),
        'model' => env('AI_MEAL_INTERPRETER_MODEL', 'openai/gpt-4o-mini'),
    ],

    'diet_insight_generator' => [
        'provider' => env('AI_DIET_INSIGHT_PROVIDER', $gateway),
        'model' => env('AI_DIET_INSIGHT_MODEL', 'openai/gpt-4o'),
    ],

    // Foody Score daily insight wording (spec §14). The engine ranks; this
    // model only words the survivors in the one Foody voice.
    'score_insight_writer' => [
        'provider' => env('AI_SCORE_INSIGHT_PROVIDER', $gateway),
        'model' => env('AI_SCORE_INSIGHT_MODEL', 'openai/gpt-4o'),
    ],

    // AI chef (Pantry): stock + goal + constraints -> breakfast/lunch/dinner
    // ideas with short recipes. Text-only.
    'recipe_suggester' => [
        'provider' => env('AI_RECIPE_PROVIDER', $gateway),
        'model' => env('AI_RECIPE_MODEL', 'openai/gpt-4o-mini'),
    ],

    // Eating-out estimation (capture flow, BUILD_PLAN §1b tier 3): dish + venue
    // -> estimated figures. Text-only, so cheap models do well here.
    'eating_out_estimator' => [
        'provider' => env('AI_EATING_OUT_PROVIDER', $gateway),
        'model' => env('AI_EATING_OUT_MODEL', 'openai/gpt-4o-mini'),
    ],

];
