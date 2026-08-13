<?php

namespace App\Services;

use App\AI\DataObjects\IdentifiedProduct;
use App\Enums\ResolutionStatus;
use App\Models\CanonicalProduct;
use App\Models\ProductResolutionJob;
use App\Models\User;
use App\Services\OpenFoodFacts\OpenFoodFactsImporter;

/**
 * Resolves a detected product to a canonical record, strongest identifier first
 * (BUILD_PLAN J2.4; brief §7.2 Step 3, §7.3, §21 Q13/Q14).
 *
 * Order:
 *   1. BARCODE  — exact local GTIN match; failing that, an Open Food Facts
 *                 lookup that creates the canonical product. Deterministic,
 *                 highest confidence.
 *   2. EXACT    — normalised brand + name + variant + pack-size match locally.
 *   3. FUZZY    — normalised similarity across canonical products. At/above the
 *                 auto-match threshold it becomes identity; between that and the
 *                 suggestion floor it is a SUGGESTION only (never auto-canonical,
 *                 brief §7.2 Step 3); below the floor it is unknown.
 *   4. UNKNOWN  — classified `needs_research` (the research workflow is M3).
 *
 * Every call records exactly one {@see ProductResolutionJob} audit row.
 */
class ProductResolver
{
    /*
    |--------------------------------------------------------------------------
    | Confidence thresholds / bands (brief §21 Q14) — documented constants.
    |--------------------------------------------------------------------------
    | Fuzzy similarity is a 0..1 score (normalised `similar_text`). The bands:
    |
    |   score >= FUZZY_AUTO_MATCH_THRESHOLD   → MatchedFuzzy (becomes identity)
    |   score >= FUZZY_SUGGESTION_THRESHOLD   → Suggestion   (confirm with user)
    |   score <  FUZZY_SUGGESTION_THRESHOLD   → NeedsResearch (unknown)
    |
    | Barcode and exact matches are deterministic and resolve at confidence 1.0
    | regardless of these bands.
    */

    /** At/above this fuzzy score a match may silently become canonical identity. */
    public const FUZZY_AUTO_MATCH_THRESHOLD = 0.85;

    /** Between this floor and the auto-match threshold, a hit is a suggestion only. */
    public const FUZZY_SUGGESTION_THRESHOLD = 0.60;

    public function __construct(private readonly OpenFoodFactsImporter $importer) {}

    public function resolve(IdentifiedProduct $detected, ?User $user = null, array $meta = []): ResolutionResult
    {
        // 1. Barcode / GTIN — strongest identifier.
        if ($detected->barcode !== null) {
            $result = $this->resolveByBarcode($detected, $user, $meta);

            if ($result !== null) {
                return $result;
            }
            // Barcode present but unresolvable: fall through to name matching.
        }

        // 2. Exact brand + name + variant + pack-size.
        $exact = $this->findExact($detected);

        if ($exact !== null) {
            return $this->finalize(ResolutionStatus::MatchedExact, $detected, $user, $meta, $exact, 1.0);
        }

        // 3. Fuzzy similarity.
        [$candidate, $score] = $this->findFuzzy($detected);

        if ($candidate !== null && $score >= self::FUZZY_AUTO_MATCH_THRESHOLD) {
            return $this->finalize(ResolutionStatus::MatchedFuzzy, $detected, $user, $meta, $candidate, $score, $score);
        }

        if ($candidate !== null && $score >= self::FUZZY_SUGGESTION_THRESHOLD) {
            // Suggestion only — NOT canonical identity until the user confirms.
            return $this->finalize(ResolutionStatus::Suggestion, $detected, $user, $meta, $candidate, $score, $score);
        }

        // 4. Unknown — hand to research (Milestone 3).
        return $this->finalize(ResolutionStatus::NeedsResearch, $detected, $user, $meta, null, $detected->confidence, $score);
    }

    private function resolveByBarcode(IdentifiedProduct $detected, ?User $user, array $meta): ?ResolutionResult
    {
        $barcode = $detected->barcode;

        $local = CanonicalProduct::where('gtin', $barcode)->first();

        if ($local !== null) {
            return $this->finalize(ResolutionStatus::MatchedBarcode, $detected, $user, $meta, $local, 1.0);
        }

        $imported = $this->importer->importByBarcode($barcode);

        if ($imported !== null) {
            return $this->finalize(ResolutionStatus::MatchedBarcode, $detected, $user, $meta, $imported, 1.0);
        }

        return null;
    }

    private function findExact(IdentifiedProduct $detected): ?CanonicalProduct
    {
        if ($detected->brand === null || $detected->productName === null) {
            return null;
        }

        $brand = $this->normalise($detected->brand);
        $name = $this->normalise($detected->productName);
        $variant = $this->normalise($detected->variant);
        $packSize = $this->normalisePackSize($detected->packSize);

        return CanonicalProduct::query()
            ->get()
            ->first(function (CanonicalProduct $product) use ($brand, $name, $variant, $packSize): bool {
                return $this->normalise($product->brand) === $brand
                    && $this->normalise($product->name) === $name
                    && $this->normalise($product->variant) === $variant
                    && $this->packSizeMatches($product, $packSize);
            });
    }

    /**
     * Best fuzzy candidate and its 0..1 similarity score.
     *
     * @return array{0: CanonicalProduct|null, 1: float}
     */
    private function findFuzzy(IdentifiedProduct $detected): array
    {
        $needle = $this->normalise(trim(implode(' ', array_filter([
            $detected->brand,
            $detected->productName,
            $detected->variant,
        ]))));

        if ($needle === '') {
            return [null, 0.0];
        }

        $best = null;
        $bestScore = 0.0;

        foreach (CanonicalProduct::query()->get() as $product) {
            $hay = $this->normalise(trim(implode(' ', array_filter([
                $product->brand,
                $product->name,
                $product->variant,
            ]))));

            if ($hay === '') {
                continue;
            }

            similar_text($needle, $hay, $percent);
            $score = $percent / 100.0;

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $product;
            }
        }

        return [$best, $bestScore];
    }

    private function finalize(
        ResolutionStatus $status,
        IdentifiedProduct $detected,
        ?User $user,
        array $meta,
        ?CanonicalProduct $product,
        float $confidence,
        ?float $score = null,
    ): ResolutionResult {
        $job = ProductResolutionJob::create([
            'user_id' => $user?->id,
            'detected_fields' => $detected->toArray(),
            'detection_confidence' => $detected->confidence,
            'matched_product_id' => $product?->id,
            'status' => $status->value,
            'model_provider' => $meta['model_provider'] ?? null,
            'model_name' => $meta['model_name'] ?? null,
            'latency_ms' => $meta['latency_ms'] ?? null,
        ]);

        return new ResolutionResult($status, $product, $confidence, $job, $score);
    }

    /** Lowercase, strip punctuation, collapse whitespace. Null → ''. */
    private function normalise(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    /**
     * Normalise a pack-size string ("189 g", "330ml") to "<value><unit>", or ''
     * when absent/unparseable, so equal sizes compare equal regardless of format.
     */
    private function normalisePackSize(?string $packSize): string
    {
        if ($packSize === null) {
            return '';
        }

        if (preg_match('/([\d]+(?:[.,]\d+)?)\s*([a-zA-Z]+)/', $packSize, $m) === 1) {
            return ((float) str_replace(',', '.', $m[1])).strtolower($m[2]);
        }

        return '';
    }

    private function packSizeMatches(CanonicalProduct $product, string $detectedPackSize): bool
    {
        // No size on either side (or unreadable) — do not fail the exact match on it.
        if ($detectedPackSize === '' || $product->pack_size_value === null) {
            return true;
        }

        $productPackSize = ((float) $product->pack_size_value).strtolower((string) $product->pack_size_unit);

        return $productPackSize === $detectedPackSize;
    }
}
