<?php

namespace App\Enums;

/**
 * Where a piece of product/nutrition evidence came from — provenance is a
 * first-class citizen (BUILD_PLAN §5, §7.13; brief §2.2). Ordered loosely by
 * the source hierarchy (§7.5): authoritative open data first, LLM estimate last.
 */
enum SourceType: string
{
    case OpenFoodFacts = 'open_food_facts';
    case Manufacturer = 'manufacturer';
    case Retailer = 'retailer';
    case LabelOcr = 'label_ocr';
    case UserConfirmed = 'user_confirmed';
    case LlmEstimate = 'llm_estimate';
}
