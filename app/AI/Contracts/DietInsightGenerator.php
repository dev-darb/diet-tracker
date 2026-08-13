<?php

namespace App\AI\Contracts;

use App\AI\DataObjects\DietInsightContext;
use App\AI\DataObjects\GeneratedInsight;
use App\AI\Local\RuleBasedDietInsightGenerator;
use App\AI\OpenRouter\PrismDietInsightGenerator;
use App\Providers\AiServiceProvider;

/**
 * Insight-generation capability (BUILD_PLAN §6 J7.1; brief §9.6/§9.8, §4.3).
 *
 * Takes DETERMINISTIC structured analytics in (a {@see DietInsightContext}
 * computed by NutritionAnalyticsService + the pantry, never by the LLM) and
 * returns ONE prioritised, pantry-aware, human-readable {@see GeneratedInsight}.
 *
 * Domain code depends ONLY on this contract, never on Prism or any provider
 * type (BUILD_PLAN idea #3). Two implementations sit behind it:
 *
 *  - {@see RuleBasedDietInsightGenerator} — deterministic, no AI,
 *    the default fallback so a useful insight ships with no API key;
 *  - {@see PrismDietInsightGenerator} — LLM phrasing over the
 *    same deterministic figures.
 *
 * The bound implementation is chosen by key presence in {@see AiServiceProvider}.
 */
interface DietInsightGenerator
{
    /**
     * Interpret the deterministic context into a single prioritised insight.
     * Implementations must NOT compute or invent any figure — the numbers are
     * already in the context; they only explain and prioritise (brief §9.9).
     */
    public function generate(DietInsightContext $context): GeneratedInsight;
}
