<?php

namespace App\Services\OpenFoodFacts;

use App\Enums\ProductVerificationStatus;
use App\Enums\ServingBasis;
use App\Enums\SourceType;
use App\Models\CanonicalProduct;
use App\Models\ProductSource;
use App\Models\ProductVersion;
use App\Nutrition\NutrientRegistry;
use App\Nutrition\NutritionSanityCheck;
use App\ValueObjects\NutrientValues;
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

        return DB::transaction(fn (): CanonicalProduct => $this->persist($product));
    }

    /**
     * Write the canonical product, its nutrition version and its provenance.
     * Assumes it runs inside a database transaction.
     */
    private function persist(OffProduct $product): CanonicalProduct
    {
        $pack = $product->packSize();
        $serving = $product->servingSize();
        $nutrition = $product->nutrition();

        $canonical = CanonicalProduct::create([
            'gtin' => $product->barcode,
            // OFF strings are third-party and unbounded; the columns are
            // varchar(255). Postgres rejects an over-length value (SQLite does
            // not), so truncate defensively — a valid scan must never 500.
            'brand' => $this->limit($product->brand) ?? 'Unknown brand',
            'name' => $this->limit($product->productName) ?? 'Unknown product',
            'variant' => null,
            // A real mass or volume, or nothing. Never a count of "bars".
            'pack_size_value' => $pack?->inBaseUnit(),
            'pack_size_unit' => $pack?->unit->value,
            // OFF's most specific category tag, in plain words — feeds
            // fruit-&-veg portions and plant-diversity classification.
            'category' => $this->limit($product->category()),
            // OFF's front-of-pack photo (their CDN, hotlink-safe): food
            // imagery is functional UI — pantry rows and result cards
            // show the food, not a glyph, whenever an image exists.
            'primary_image_path' => $this->urlOrNull($product->imageUrl),
        ]);

        // Cross-checks on figures that are individually possible but cannot all
        // be true at once. Findings never alter the data — they drop the version
        // to needs-review so a person can look, while it stays usable.
        $quality = NutritionSanityCheck::columns(
            NutrientValues::fromStated($nutrition['values']),
            $serving,
            $pack,
        );

        $version = $canonical->versions()->create([
            'serving_basis' => ServingBasis::Per100g,
            'serving_size_value' => $serving?->inBaseUnit(),
            'serving_size_unit' => $serving?->unit->value,
            // Per-100g figures; each may be null (unknown) — persisted as NULL.
            ...$nutrition['values'],
            ...$quality,
            'ingredients' => $product->ingredientsText,
            'allergens' => $product->allergens,
            'effective_from' => now(),
            'verified_at' => now(),
            'status' => $quality['sanity_findings'] === null
                ? ProductVerificationStatus::AutoVerified
                : ProductVerificationStatus::NeedsReview,
        ]);

        $version->sources()->create([
            'source_url' => "{$this->baseUrl()}/product/{$product->barcode}",
            'source_type' => SourceType::OpenFoodFacts,
            'retrieved_at' => now(),
            'confidence' => self::SOURCE_CONFIDENCE,
            'evidence_summary' => $this->evidenceSummary($product, $nutrition),
        ]);

        return $canonical->refresh();
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

    /**
     * Provenance in words: what was stated, what had to be derived, and what OFF
     * simply does not say. Derivations are named explicitly (energy from
     * kilojoules, salt from sodium, a figure renormalised from a per-serving
     * statement) because a derived number is real data and a fabricated one is
     * not — and six months from now the difference has to still be visible.
     *
     * @param  array{values: array<string, float|null>, derived: array<string, string>}  $nutrition
     */
    private function evidenceSummary(OffProduct $product, array $nutrition): string
    {
        $per = $product->nutritionDataPer ?? '100g';
        $summary = "Imported from Open Food Facts (barcode {$product->barcode}); nutrition per {$per}.";

        if ($nutrition['derived'] !== []) {
            $derived = [];

            foreach ($nutrition['derived'] as $key => $sourceKey) {
                $derived[] = "{$key} from {$sourceKey}";
            }

            $summary .= ' Derived: '.implode(', ', $derived).'.';
        }

        // Macros only: naming all fifteen unstated micronutrients on every
        // product would bury the macro gaps that actually matter.
        $unknownMacros = array_keys(array_filter(
            array_intersect_key($nutrition['values'], array_flip(NutrientValues::MACRO_KEYS)),
            static fn ($v) => $v === null,
        ));

        if ($unknownMacros !== []) {
            $summary .= ' Not stated by OFF: '.implode(', ', $unknownMacros).'.';
        }

        $statedMicros = count(array_filter(
            array_intersect_key($nutrition['values'], array_flip(NutrientRegistry::microKeys())),
            static fn ($v) => $v !== null,
        ));

        $summary .= sprintf(
            ' Micronutrients stated: %d of %d.',
            $statedMicros,
            count(NutrientRegistry::microKeys()),
        );

        return $summary;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.open_food_facts.base_url'), '/');
    }
}
