<?php

namespace App\Services\OpenFoodFacts;

use App\Nutrition\MeasuredAmount;
use App\Nutrition\NutrientRegistry;

/**
 * A normalised view over one Open Food Facts product payload (brief §7.5, D3).
 *
 * Wraps the raw API `product` object and exposes exactly the fields the importer
 * needs, keeping OFF's sprawling schema out of the rest of the app. Crucially,
 * nutrient accessors return `null` when OFF does not state a value — the caller
 * must persist that as unknown, never as 0 (brief §2.1).
 *
 * Every key read here must also appear in {@see OpenFoodFactsClient::FIELDS}.
 * The audit (Aug 2026) found that it did not: this class read `categories_tags`
 * and the image URLs, the request never asked for them, and so every product
 * silently arrived with no category and no photo.
 */
final class OffProduct
{
    /**
     * @param  array<string, mixed>  $nutriments
     * @param  array<int, string>  $allergens
     * @param  array<int, string>  $categories
     */
    private function __construct(
        public readonly string $barcode,
        public readonly ?string $productName,
        public readonly ?string $brand,
        public readonly ?string $quantity,
        public readonly ?string $servingSize,
        public readonly ?string $nutritionDataPer,
        public readonly ?string $ingredientsText,
        public readonly array $allergens,
        public readonly array $categories,
        public readonly ?string $imageUrl,
        private readonly array $nutriments,
        private readonly ?MeasuredAmount $packSize,
        private readonly ?MeasuredAmount $servingAmount,
    ) {}

    /**
     * @param  array<string, mixed>  $product  the API `product` object.
     */
    public static function fromApi(string $barcode, array $product): self
    {
        return new self(
            barcode: $barcode,
            productName: self::string($product['product_name'] ?? null),
            brand: self::firstBrand($product['brands'] ?? null),
            quantity: self::string($product['quantity'] ?? null),
            servingSize: self::string($product['serving_size'] ?? null),
            nutritionDataPer: self::string($product['nutrition_data_per'] ?? null),
            ingredientsText: self::string($product['ingredients_text'] ?? null),
            allergens: self::allergens($product['allergens_tags'] ?? []),
            categories: self::categories($product['categories_tags'] ?? []),
            imageUrl: self::imageUrl($product),
            nutriments: is_array($product['nutriments'] ?? null) ? $product['nutriments'] : [],
            packSize: self::readPackSize($product),
            servingAmount: self::readServingSize($product),
        );
    }

    /**
     * The most specific OFF category, in plain words ("rolled oats"), or null
     * when OFF states none. OFF orders `categories_tags` general → specific;
     * the last tag is the product's own shelf, which is what fruit-&-veg and
     * plant-diversity classification key on.
     */
    public function category(): ?string
    {
        return $this->categories === []
            ? null
            : $this->categories[array_key_last($this->categories)];
    }

    /**
     * Net pack contents as a real mass or volume, or null when OFF states none
     * we can read. A pack size we cannot read stays unknown rather than wrong —
     * getting this wrong is what made a 1 kg bag of rice weigh one gram (D4/D5).
     */
    public function packSize(): ?MeasuredAmount
    {
        return $this->packSize;
    }

    /** The stated serving as a real mass or volume, or null. */
    public function servingSize(): ?MeasuredAmount
    {
        return $this->servingAmount;
    }

    /**
     * Every tracked nutrient on a per-100g/ml basis, each `null` when OFF does
     * not state it. Keyed to match a product_versions row.
     *
     * Reading is delegated to {@see NutrientRegistry}, which owns the fallbacks
     * the audit found missing: energy stated only in kilojoules, salt stated only
     * as sodium, and figures stated only per serving on a product whose serving
     * size we know. Each of those is arithmetic on real data — a derivation, not
     * an invention — and the caller is told which figures needed one.
     *
     * @return array{values: array<string, float|null>, derived: array<string, string>}
     */
    public function nutrition(): array
    {
        return NutrientRegistry::readPayload(
            $this->nutriments,
            $this->servingAmount?->inBaseUnit(),
        );
    }

    /**
     * The per-100g/ml figures alone.
     *
     * @return array<string, float|null>
     */
    public function per100gNutrients(): array
    {
        return $this->nutrition()['values'];
    }

    /**
     * Net contents. `product_quantity` + `product_quantity_unit` is OFF's own
     * structured, unit-tagged figure and is trusted first; the human `quantity`
     * string ("6 x 25 g", "1kg") is parsed only when it is absent.
     *
     * @param  array<string, mixed>  $product
     */
    private static function readPackSize(array $product): ?MeasuredAmount
    {
        return MeasuredAmount::fromNumeric(
            $product['product_quantity'] ?? null,
            self::string($product['product_quantity_unit'] ?? null),
        ) ?? MeasuredAmount::parse(self::string($product['quantity'] ?? null));
    }

    /**
     * Serving size. Here the human string is read FIRST: it carries the unit
     * ("250 ml" stays a volume), whereas `serving_quantity` is a bare number
     * whose unit tag is often missing — and assuming grams for a drink would
     * quietly mis-scale every figure derived from it.
     *
     * @param  array<string, mixed>  $product
     */
    private static function readServingSize(array $product): ?MeasuredAmount
    {
        return MeasuredAmount::parse(self::string($product['serving_size'] ?? null))
            ?? MeasuredAmount::fromNumeric(
                $product['serving_quantity'] ?? null,
                self::string($product['serving_quantity_unit'] ?? null),
            );
    }

    private static function firstBrand(mixed $brands): ?string
    {
        $brands = self::string($brands);

        if ($brands === null) {
            return null;
        }

        // OFF stores brands as a comma-separated list; take the first.
        return self::string(explode(',', $brands)[0] ?? null);
    }

    /**
     * @param  mixed  $tags  e.g. ["en:milk", "en:gluten"].
     * @return array<int, string>
     */
    private static function allergens(mixed $tags): array
    {
        if (! is_array($tags)) {
            return [];
        }

        return array_values(array_filter(array_map(static function ($tag): ?string {
            $tag = self::string($tag);

            if ($tag === null) {
                return null;
            }

            // Strip the leading language prefix, e.g. "en:milk" → "milk".
            $parts = explode(':', $tag);

            return self::string(end($parts));
        }, $tags)));
    }

    /**
     * Clean OFF category tags into plain lowercase words, dropping the
     * language prefix and hyphens: "en:plant-based-foods" → "plant based foods".
     *
     * @return array<int, string>
     */
    private static function categories(mixed $tags): array
    {
        if (! is_array($tags)) {
            return [];
        }

        return array_values(array_filter(array_map(static function ($tag): ?string {
            $tag = self::string($tag);

            if ($tag === null) {
                return null;
            }

            $parts = explode(':', $tag);

            return self::string(str_replace('-', ' ', strtolower((string) end($parts))));
        }, $tags)));
    }

    /**
     * The front-of-pack photo URL from OFF's CDN — the small rendition where
     * available (row-scale imagery needs ~200px, not the 400px original).
     * Food imagery is functional UI, not decoration; null stays null.
     *
     * @param  array<string, mixed>  $product
     */
    private static function imageUrl(array $product): ?string
    {
        foreach (['image_front_small_url', 'image_front_url', 'image_small_url', 'image_url'] as $key) {
            $url = self::string($product[$key] ?? null);

            if ($url !== null && str_starts_with($url, 'https://')) {
                return $url;
            }
        }

        return null;
    }

    private static function string(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
