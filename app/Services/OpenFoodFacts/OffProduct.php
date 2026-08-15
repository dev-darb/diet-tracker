<?php

namespace App\Services\OpenFoodFacts;

use App\ValueObjects\NutrientValues;

/**
 * A normalised view over one Open Food Facts product payload (brief §7.5, D3).
 *
 * Wraps the raw API `product` object and exposes exactly the fields the importer
 * needs, keeping OFF's sprawling schema out of the rest of the app. Crucially,
 * nutrient accessors return `null` when OFF does not state a value — the caller
 * must persist that as unknown, never as 0 (brief §2.1).
 */
final class OffProduct
{
    /**
     * @param  array<string, mixed>  $nutriments
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
        private readonly array $nutriments,
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
            nutriments: is_array($product['nutriments'] ?? null) ? $product['nutriments'] : [],
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
     * The eight tracked macros on a per-100g/ml basis, each `null` when OFF does
     * not state it. Keyed by {@see NutrientValues::KEYS} so the
     * result maps straight onto a product_versions row.
     *
     * @return array<string, float|null>
     */
    public function per100gNutrients(): array
    {
        return [
            'calories' => $this->nutriment('energy-kcal_100g'),
            'protein' => $this->nutriment('proteins_100g'),
            'carbs' => $this->nutriment('carbohydrates_100g'),
            'sugars' => $this->nutriment('sugars_100g'),
            'fat' => $this->nutriment('fat_100g'),
            'saturated_fat' => $this->nutriment('saturated-fat_100g'),
            'fibre' => $this->nutriment('fiber_100g'),
            'salt' => $this->nutriment('salt_100g'),
        ];
    }

    /**
     * Largest per-100g nutrient value we will trust/store. The nutrient columns
     * are decimal(8,2) (must round to < 10^6), and OFF is crowd-sourced with
     * frequent data-entry errors (wrong units, stray digits). A value beyond any
     * physically plausible per-100g figure is treated as unknown rather than
     * stored — storing it would overflow Postgres and 500 the whole scan.
     */
    private const MAX_NUTRIENT = 100000.0;

    /**
     * A single numeric nutriment, or null when OFF omits it (never fabricate 0)
     * or states an impossible value (out of range / negative → treated as unknown).
     */
    public function nutriment(string $key): ?float
    {
        if (! array_key_exists($key, $this->nutriments)) {
            return null;
        }

        $value = $this->nutriments[$key];

        if (! is_numeric($value)) {
            return null;
        }

        $value = (float) $value;

        if (! is_finite($value) || $value < 0.0 || $value > self::MAX_NUTRIENT) {
            return null;
        }

        return $value;
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
     * @param  mixed  $tags
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

    private static function string(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
