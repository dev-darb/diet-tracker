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
     * Identify a single packaged product from an image, returning the extracted
     * identity fields and a self-reported confidence. Implementations must log
     * an `ai_jobs` diagnostics row for every call (via {@see AiJobLogger}).
     */
    public function identify(ProductImage $image): IdentifiedProduct;
}
