<?php

namespace App\AI\Contracts;

/**
 * SCAFFOLD ONLY — implemented in Milestone 7 (brief §9.6/§9.8, §4.3).
 *
 * Marks the future insight-generation capability: take DETERMINISTIC structured
 * analytics in (computed by NutritionAnalyticsService, never by the LLM) and
 * return one or two prioritised, pantry-aware, human-readable observations.
 * Marker interface only; the method surface is fixed when M7 is built.
 */
interface DietInsightGenerator {}
