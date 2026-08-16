<?php

namespace App\Services;

use App\Enums\ProductVerificationStatus;
use App\Enums\SourceType;
use App\Models\CanonicalProduct;
use App\Models\ProductVersion;
use App\Nutrition\MeasuredAmount;
use App\Nutrition\NutritionSanityCheck;
use App\ValueObjects\NutrientValues;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * All non-trivial persistence for canonical products + versions (BUILD_PLAN
 * §11, §20 Phase 1). Keeps the admin Livewire components thin: they validate
 * and delegate here. This is the "manual product creation" path — every version
 * created this way records a `user_confirmed` provenance row (brief §2.2/§7.13).
 */
class CanonicalProductService
{
    /** Canonical-identity columns that a product form owns. */
    private const PRODUCT_FIELDS = [
        'gtin', 'brand', 'name', 'variant',
        'pack_size_value', 'pack_size_unit', 'category',
    ];

    /**
     * Create a canonical product, optionally with an initial nutrition version.
     *
     * @param  array<string, mixed>  $productData
     * @param  array<string, mixed>|null  $versionData  when present, a version +
     *                                                  `user_confirmed` source
     *                                                  is attached atomically.
     */
    public function createProduct(array $productData, ?array $versionData = null): CanonicalProduct
    {
        return DB::transaction(function () use ($productData, $versionData) {
            $product = CanonicalProduct::create($this->productAttributes($productData));

            if ($versionData !== null) {
                $this->addVersion($product, $versionData);
            }

            return $product->refresh();
        });
    }

    /**
     * Update a canonical product's identity fields.
     *
     * @param  array<string, mixed>  $productData
     */
    public function updateProduct(CanonicalProduct $product, array $productData): CanonicalProduct
    {
        $product->update($this->productAttributes($productData));

        return $product;
    }

    /**
     * Attach a nutrition version to a product with its provenance. Manual entry
     * is always `user_confirmed`; `confidence` records how sure the operator is.
     *
     * @param  array<string, mixed>  $versionData
     */
    public function addVersion(CanonicalProduct $product, array $versionData): ProductVersion
    {
        return DB::transaction(function () use ($product, $versionData) {
            $status = $versionData['status'] instanceof ProductVerificationStatus
                ? $versionData['status']
                : ProductVerificationStatus::from($versionData['status'] ?? ProductVerificationStatus::Verified->value);

            $values = NutrientValues::fromStated($versionData);

            // The same cross-checks the importer runs. A hand-entered version is
            // not exempt: transposing sugars and carbohydrate is easier by hand
            // than by API. Findings are recorded beside the data, not applied to it.
            $quality = NutritionSanityCheck::columns(
                $values,
                MeasuredAmount::fromNumeric(
                    $versionData['serving_size_value'] ?? null,
                    $versionData['serving_size_unit'] ?? null,
                ),
                $product->packSize(),
            );

            $verifiedAt = in_array($status, [
                ProductVerificationStatus::Verified,
                ProductVerificationStatus::AutoVerified,
            ], true) ? now() : null;

            $version = $product->versions()->create([
                'serving_basis' => $versionData['serving_basis'],
                'serving_size_value' => $versionData['serving_size_value'] ?? null,
                'serving_size_unit' => $versionData['serving_size_unit'] ?? null,
                // AUDIT D7: a nutrient left blank is UNKNOWN, not zero. This used
                // to read `?? 0` per column, so the one path a founder uses to
                // correct bad data was the one path that fabricated it — silently
                // claiming a product contains no fibre because nobody typed a
                // figure in. fromStated() is the constructor that cannot do that.
                ...$values->toArray(),
                ...$quality,
                'ingredients' => $versionData['ingredients'] ?? null,
                'allergens' => $this->normaliseAllergens($versionData['allergens'] ?? []),
                'effective_from' => now(),
                'verified_at' => $verifiedAt,
                'status' => $status,
            ]);

            $version->sources()->create([
                'source_url' => $versionData['source_url'] ?? null,
                'source_type' => SourceType::UserConfirmed,
                'retrieved_at' => now(),
                'confidence' => $versionData['confidence'] ?? 1.0,
                'evidence_summary' => $versionData['evidence_summary'] ?? 'Manually entered in the admin console.',
            ]);

            return $version;
        });
    }

    /**
     * Whitelist + normalise the canonical-identity fields. An empty gtin becomes
     * null so the "unique when present" index is not tripped by blank strings.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function productAttributes(array $data): array
    {
        $attributes = Arr::only($data, self::PRODUCT_FIELDS);

        foreach (['gtin', 'variant', 'category', 'pack_size_unit'] as $optional) {
            if (array_key_exists($optional, $attributes) && $attributes[$optional] === '') {
                $attributes[$optional] = null;
            }
        }

        return $attributes;
    }

    /**
     * @param  array<int, string>|string  $allergens
     * @return array<int, string>
     */
    private function normaliseAllergens(array|string $allergens): array
    {
        if (is_string($allergens)) {
            $allergens = preg_split('/[\n,]+/', $allergens) ?: [];
        }

        return collect($allergens)
            ->map(fn ($a) => trim((string) $a))
            ->filter()
            ->values()
            ->all();
    }
}
