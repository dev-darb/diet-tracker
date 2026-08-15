<?php

namespace App\Services\OpenFoodFacts;

use App\Enums\ProductVerificationStatus;
use App\Enums\ServingBasis;
use App\Enums\SourceType;
use App\Models\CanonicalProduct;
use App\Models\ProductSource;
use App\Models\ProductVersion;
use Illuminate\Support\Facades\DB;

/**
 * Turns an Open Food Facts product into our canonical model (BUILD_PLAN J2.3;
 * brief §7.5, §10.2). Creates a {@see CanonicalProduct} (identity), a
 * per-100g {@see ProductVersion} (nutrition, with unknown fields
 * left NULL — never fabricated 0, §2.1), and an `open_food_facts`
 * {@see ProductSource} (provenance).
 *
 * IDEMPOTENT on GTIN: if a canonical product already exists for the barcode it
 * is returned untouched and no duplicate is created (brief §10.4). OFF data is a
 * strong-but-not-user-confirmed source, so the version is written as
 * `auto_verified` — real, source-backed data, but not yet user-confirmed.
 */
class OpenFoodFactsImporter
{
    /** OFF is authoritative open data — high but not perfect confidence. */
    private const SOURCE_CONFIDENCE = 0.90;

    public function __construct(private readonly OpenFoodFactsClient $client) {}

    /**
     * Look a barcode up on OFF and import it. Returns null when OFF has no match
     * (or is unreachable) — the resolver then treats the product as unknown.
     */
    public function importByBarcode(string $barcode): ?CanonicalProduct
    {
        $product = $this->client->fetchByBarcode($barcode);

        return $product === null ? null : $this->import($product);
    }

    /**
     * Persist an OFF product idempotently on its GTIN.
     */
    public function import(OffProduct $product): CanonicalProduct
    {
        $existing = CanonicalProduct::where('gtin', $product->barcode)->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($product): CanonicalProduct {
            $canonical = CanonicalProduct::create([
                'gtin' => $product->barcode,
                // OFF strings are third-party and unbounded; the columns are
                // varchar(255). Postgres rejects an over-length value (SQLite does
                // not), so truncate defensively — a valid scan must never 500.
                'brand' => $this->limit($product->brand) ?? 'Unknown brand',
                'name' => $this->limit($product->productName) ?? 'Unknown product',
                'variant' => null,
                ...$this->packSize($product->quantity),
                // OFF's most specific category tag, in plain words — feeds
                // fruit-&-veg portions and plant-diversity classification.
                'category' => $this->limit($product->category()),
                // OFF's front-of-pack photo (their CDN, hotlink-safe): food
                // imagery is functional UI — pantry rows and result cards
                // show the food, not a glyph, whenever an image exists.
                'primary_image_path' => $this->urlOrNull($product->imageUrl),
            ]);

            $version = $canonical->versions()->create([
                'serving_basis' => ServingBasis::Per100g,
                ...$this->servingSize($product->servingSize),
                // Per-100g macros; each may be null (unknown) — persisted as NULL.
                ...$product->per100gNutrients(),
                'ingredients' => $product->ingredientsText,
                'allergens' => $product->allergens,
                'effective_from' => now(),
                'verified_at' => now(),
                'status' => ProductVerificationStatus::AutoVerified,
            ]);

            $version->sources()->create([
                'source_url' => "{$this->baseUrl()}/product/{$product->barcode}",
                'source_type' => SourceType::OpenFoodFacts,
                'retrieved_at' => now(),
                'confidence' => self::SOURCE_CONFIDENCE,
                'evidence_summary' => $this->evidenceSummary($product),
            ]);

            return $canonical->refresh();
        });
    }

    /**
     * Parse an OFF `quantity` string (e.g. "330 ml", "189g", "1 L") into a
     * pack-size value + unit. Returns nulls when it cannot be parsed — a pack
     * size we cannot read stays unknown rather than wrong.
     *
     * @return array{pack_size_value: float|null, pack_size_unit: string|null}
     */
    private function packSize(?string $quantity): array
    {
        return $this->parseAmount($quantity, 'pack_size_value', 'pack_size_unit');
    }

    /**
     * Parse an OFF `serving_size` string into a numeric value + unit for the
     * version row (used by NutritionCalculator for per-serving conversions).
     *
     * @return array{serving_size_value: float|null, serving_size_unit: string|null}
     */
    private function servingSize(?string $servingSize): array
    {
        return $this->parseAmount($servingSize, 'serving_size_value', 'serving_size_unit');
    }

    /**
     * @return array<string, float|string|null>
     */
    private function parseAmount(?string $raw, string $valueKey, string $unitKey): array
    {
        if ($raw !== null && preg_match('/([\d]+(?:[.,]\d+)?)\s*([a-zA-Z]+)/', $raw, $m) === 1) {
            $value = (float) str_replace(',', '.', $m[1]);

            // The value/unit columns are decimal(10,3)/varchar; a malformed OFF
            // string could parse to something that overflows on Postgres, so a
            // wildly out-of-range amount is treated as unparseable (stays null).
            if ($value <= 0.0 || $value >= 9_999_999.0) {
                return [$valueKey => null, $unitKey => null];
            }

            return [
                $valueKey => $value,
                $unitKey => $this->limit(strtolower($m[2]), 16),
            ];
        }

        return [$valueKey => null, $unitKey => null];
    }

    /** A URL either fits its varchar(255) column intact or is dropped — never truncated into a broken link. */
    private function urlOrNull(?string $url): ?string
    {
        return $url !== null && mb_strlen($url) <= 255 ? $url : null;
    }

    /** Truncate a third-party string to fit its column (multibyte-safe). */
    private function limit(?string $value, int $max = 255): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }

    private function evidenceSummary(OffProduct $product): string
    {
        $per = $product->nutritionDataPer ?? '100g';
        $unknown = array_keys(array_filter($product->per100gNutrients(), static fn ($v) => $v === null));

        $summary = "Imported from Open Food Facts (barcode {$product->barcode}); nutrition per {$per}.";

        if ($unknown !== []) {
            $summary .= ' Not stated by OFF: '.implode(', ', $unknown).'.';
        }

        return $summary;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.open_food_facts.base_url'), '/');
    }
}
