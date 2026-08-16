<?php

namespace App\Services\OpenFoodFacts;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin HTTP client for the Open Food Facts product API (BUILD_PLAN D3, J2.3;
 * brief §7.5). Deterministic barcode → nutrition lookup, consulted BEFORE any
 * LLM (§2.1).
 *
 * Reliability contract: this never throws into the request path. A missing
 * product, an HTTP error, or a network failure all return `null` — the resolver
 * treats "no OFF result" the same regardless of cause and falls back to the next
 * resolution strategy. OFF requires a descriptive User-Agent, which is sent on
 * every request (config/services.php `open_food_facts.user_agent`).
 */
class OpenFoodFactsClient
{
    /**
     * Only the fields the importer needs, to keep payloads small (brief §7.5 note).
     *
     * AUDIT D1 (Aug 2026): this list must contain every key {@see OffProduct}
     * reads. It previously omitted `categories_tags` and the image URLs while
     * OffProduct read both — so the API simply never returned them, and every
     * product in the app had a null category and no photo. Nothing failed loudly;
     * the plant-diversity scoring just quietly had nothing to classify, and the
     * image markup on pantry rows, scan cards and the chef never once fired.
     *
     * `nutriments` carries the whole nutrient object, micronutrients included, so
     * the registry's fifteen vitamins and minerals need no entry of their own.
     *
     * If you read a new field in OffProduct, add it here in the same commit.
     */
    private const FIELDS = 'code,product_name,brands,quantity,product_quantity,product_quantity_unit,'
        .'nutriments,ingredients_text,allergens_tags,serving_size,serving_quantity,'
        .'nutrition_data_per,categories_tags,'
        .'image_front_small_url,image_front_url,image_small_url,image_url';

    public function fetchByBarcode(string $barcode): ?OffProduct
    {
        $barcode = trim($barcode);

        if ($barcode === '') {
            return null;
        }

        try {
            $response = Http::withHeaders(['User-Agent' => $this->userAgent()])
                ->timeout($this->timeout())
                ->acceptJson()
                ->get("{$this->baseUrl()}/api/v2/product/{$barcode}.json", ['fields' => self::FIELDS]);

            if (! $response->successful()) {
                return null;
            }

            $json = $response->json();

            // status: 1 = found, 0 = not found.
            if (! is_array($json) || ($json['status'] ?? 0) !== 1) {
                return null;
            }

            $product = $json['product'] ?? null;

            if (! is_array($product)) {
                return null;
            }

            return OffProduct::fromApi($barcode, $product);
        } catch (Throwable $e) {
            // Network/parse failure must not break the scan flow (§2.1).
            Log::warning('Open Food Facts lookup failed', [
                'barcode' => $barcode,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.open_food_facts.base_url'), '/');
    }

    private function userAgent(): string
    {
        return (string) config('services.open_food_facts.user_agent');
    }

    private function timeout(): int
    {
        return (int) config('services.open_food_facts.timeout', 10);
    }
}
