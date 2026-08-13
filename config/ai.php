<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI capability → provider/model mapping (BUILD_PLAN D2, §4.3; brief §14)
    |--------------------------------------------------------------------------
    |
    | Each capability interface resolves its provider + model from here, so a
    | model can be swapped for benchmarking (brief §14) by changing config/env
    | alone — domain code never mentions a model string. The provider name is a
    | Prism provider key (see config/prism.php); the gateway is OpenRouter (D2),
    | which reaches many underlying models through one key.
    |
    | No API key is required for the app to boot or for the test suite to pass
    | (tests use Prism::fake()). A live call needs OPENROUTER_API_KEY set.
    |
    */

    'product_identifier' => [
        'provider' => env('AI_PRODUCT_IDENTIFIER_PROVIDER', 'openrouter'),
        'model' => env('AI_PRODUCT_IDENTIFIER_MODEL', 'openai/gpt-4o-mini'),
    ],

    // Scaffolded for later milestones (M3/M5/M7); not yet consumed.
    'product_researcher' => [
        'provider' => env('AI_PRODUCT_RESEARCHER_PROVIDER', 'openrouter'),
        'model' => env('AI_PRODUCT_RESEARCHER_MODEL', 'openai/gpt-4o'),
    ],

    'meal_interpreter' => [
        'provider' => env('AI_MEAL_INTERPRETER_PROVIDER', 'openrouter'),
        'model' => env('AI_MEAL_INTERPRETER_MODEL', 'openai/gpt-4o-mini'),
    ],

    'diet_insight_generator' => [
        'provider' => env('AI_DIET_INSIGHT_PROVIDER', 'openrouter'),
        'model' => env('AI_DIET_INSIGHT_MODEL', 'openai/gpt-4o'),
    ],

];
