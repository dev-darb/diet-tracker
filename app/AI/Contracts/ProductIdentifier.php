<?php

namespace App\AI\Contracts;

use App\AI\DataObjects\IdentifiedProduct;
use App\AI\DataObjects\ProductImage;
use App\AI\OpenRouter\PrismProductIdentifier;
use App\Providers\AiServiceProvider;
use App\Services\AiJobLogger;

/**
 * Capability interface for visual product identification (brief §4.3, §7.2).
 *
 * Domain code depends ONLY on this contract, never on Prism or any provider
 * type, so the model/gateway behind it can be swapped by configuration without
 * touching callers (BUILD_PLAN idea #3, D2). The concrete Prism-backed
 * implementation lives in {@see PrismProductIdentifier} and
 * is bound in {@see AiServiceProvider}.
 */
interface ProductIdentifier
{
    /**
     * Look at a food photo: classify WHAT it is (packaged product, loose
     * ingredient, prepared meal, unknown — the `kind` on the result), then
     * extract identity fields for the product kinds or a dish name for meals,
     * with a self-reported confidence. One specialised call does both — the
     * classification is a decision the vision model makes anyway before it
     * can extract anything. Implementations must log an `ai_jobs` diagnostics
     * row for every call (via {@see AiJobLogger}).
     *
     * @param  string|null  $kindHint  a user-asserted kind ("the user says this
     *                                 is a loose ingredient") when triage was
     *                                 uncertain and the card asked; null normally.
     */
    public function identify(ProductImage $image, ?string $kindHint = null): IdentifiedProduct;
}
