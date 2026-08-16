<?php

namespace App\Services\OpenFoodFacts;

use App\Enums\ProductVerificationStatus;
use App\Enums\ServingBasis;
use App\Enums\SourceType;
use App\Models\CanonicalProduct;
use App\Models\ProductVersion;
use App\Nutrition\NutrientOrigin;
use App\Nutrition\NutrientOrigins;
use App\Nutrition\NutritionSanityCheck;
use App\Services\PantryNutritionService;
use App\ValueObjects\NutrientValues;
use Illuminate\Support\Facades\DB;

/**
 * Brings one existing canonical product back into line with Open Food Facts
 * (food-intelligence tranche, Aug 2026).
 *
 * ## The rule this service exists to honour
 * Identity and metadata are CORRECTED IN PLACE — a product's name, brand,
 * category, image and pack size describe the thing itself, and a null category
 * or a pack size of one gram is simply wrong, today and retroactively.
 *
 * Nutrition is NEVER corrected in place. Corrected figures arrive as a NEW
 * {@see ProductVersion}, effective now, and the previous version is superseded
 * rather than edited. Everything already eaten keeps pointing at the version
 * that was current when it was eaten, so a historical day — and any Foody Score
 * built on it — cannot move under the user. Past days keep their old figures;
 * that is the point.
 */
class ProductRefresher
{
    /** OFF is authoritative open data — high but not perfect confidence. */
    private const SOURCE_CONFIDENCE = 0.90;

    public function __construct(
        private readonly OpenFoodFactsClient $client,
        private readonly PantryNutritionService $nutrition,
    ) {}

    public function refresh(CanonicalProduct $product, bool $dryRun = false): RefreshOutcome
    {
        $fresh = $this->client->fetchByBarcode((string) $product->gtin);

        if ($fresh === null) {
            return RefreshOutcome::unreachable();
        }

        $identity = $this->identityChanges($product, $fresh);
        $current = $this->nutrition->currentVersion($product);
        $nutrition = $fresh->nutrition();
        $serving = $fresh->servingSize();

        $nutritionMoved = $this->nutritionDiffers($current, $nutrition['values'], $serving);

        $changes = array_map(
            static fn (string $field, array $move): string => sprintf('%s: %s -> %s', $field, $move[0], $move[1]),
            array_keys($identity),
            array_values($identity),
        );

        if ($nutritionMoved) {
            $changes[] = 'nutrition: new version written (previous kept for history)';
        }

        if ($identity === [] && ! $nutritionMoved) {
            return RefreshOutcome::unchanged();
        }

        if (! $dryRun) {
            DB::transaction(function () use ($product, $fresh, $identity, $nutritionMoved, $nutrition, $serving, $current): void {
                if ($identity !== []) {
                    $product->update($this->identityAttributes($fresh));
                }

                if ($nutritionMoved) {
                    $this->writeNewVersion($product, $fresh, $nutrition, $serving, $current);
                }
            });
        }

        return RefreshOutcome::changed($identity !== [], $nutritionMoved, $changes);
    }

    /**
     * Identity fields worth correcting in place, and what they would become.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function identityChanges(CanonicalProduct $product, OffProduct $fresh): array
    {
        $next = $this->identityAttributes($fresh);
        $changes = [];

        foreach ($next as $field => $value) {
            $before = $product->getAttribute($field);

            // Loose comparison on the decimal columns, which Eloquent hands back
            // as strings ("1000.000" is not "1000" but is the same pack).
            $same = is_numeric($before) && is_numeric($value)
                ? abs((float) $before - (float) $value) < 0.001
                : (string) $before === (string) $value;

            if (! $same) {
                $changes[$field] = [$this->show($before), $this->show($value)];
            }
        }

        return $changes;
    }

    /**
     * @return array<string, mixed>
     */
    private function identityAttributes(OffProduct $fresh): array
    {
        $pack = $fresh->packSize();

        return [
            'brand' => $this->limit($fresh->brand) ?? 'Unknown brand',
            'name' => $this->limit($fresh->productName) ?? 'Unknown product',
            'pack_size_value' => $pack?->inBaseUnit(),
            'pack_size_unit' => $pack?->unit->value,
            'category' => $this->limit($fresh->category()),
            'primary_image_path' => $this->urlOrNull($fresh->imageUrl),
        ];
    }

    /**
     * Whether the source now states something materially different from the
     * version currently in effect. Compared on the stored figures themselves, so
     * a re-run with no upstream change writes nothing.
     *
     * @param  array<string, float|null>  $values
     */
    private function nutritionDiffers(?ProductVersion $current, array $values, ?object $serving): bool
    {
        if ($current === null) {
            // A product with no usable version gains one as soon as OFF states
            // anything at all.
            return array_filter($values, static fn ($v) => $v !== null) !== [];
        }

        $existing = NutrientValues::fromArray($current->only(NutrientValues::KEYS))->toArray();
        $incoming = NutrientValues::fromStated($values)->toArray();

        foreach (NutrientValues::KEYS as $key) {
            $before = $existing[$key];
            $after = $incoming[$key];

            if ($before === null xor $after === null) {
                return true;
            }

            if ($before !== null && $after !== null && abs($before - $after) > 0.0005) {
                return true;
            }
        }

        $currentServing = $current->servingSize();
        $beforeServing = $currentServing?->inBaseUnit();
        $afterServing = $serving?->inBaseUnit();

        return abs(($beforeServing ?? -1) - ($afterServing ?? -1)) > 0.001;
    }

    /**
     * @param  array{values: array<string, float|null>, derived: array<string, string>}  $nutrition
     */
    private function writeNewVersion(
        CanonicalProduct $product,
        OffProduct $fresh,
        array $nutrition,
        ?object $serving,
        ?ProductVersion $current,
    ): void {
        $quality = NutritionSanityCheck::columns(
            NutrientValues::fromStated($nutrition['values']),
            $serving,
            $product->packSize(),
        );

        $version = $product->versions()->create([
            'serving_basis' => ServingBasis::Per100g,
            'serving_size_value' => $serving?->inBaseUnit(),
            'serving_size_unit' => $serving?->unit->value,
            ...$nutrition['values'],
            ...$quality,
            'nutrient_origins' => NutrientOrigins::none()
                ->markEach($nutrition['derived'], NutrientOrigin::Derived)
                ->toArray(),
            'ingredients' => $fresh->ingredientsText,
            'allergens' => $fresh->allergens,
            'effective_from' => now(),
            'verified_at' => now(),
            'status' => $quality['sanity_findings'] === null
                ? ProductVerificationStatus::AutoVerified
                : ProductVerificationStatus::NeedsReview,
        ]);

        $version->sources()->create([
            'source_url' => rtrim((string) config('services.open_food_facts.base_url'), '/')."/product/{$fresh->barcode}",
            'source_type' => SourceType::OpenFoodFacts,
            'retrieved_at' => now(),
            'confidence' => self::SOURCE_CONFIDENCE,
            'evidence_summary' => 'Re-imported from Open Food Facts after the Aug 2026 data audit.'
                .($nutrition['derived'] !== []
                    ? ' Derived: '.implode(', ', array_map(
                        static fn (string $key, string $source): string => "{$key} from {$source}",
                        array_keys($nutrition['derived']),
                        array_values($nutrition['derived']),
                    )).'.'
                    : ''),
        ]);

        // The old version is retired, not deleted or edited: consumption items
        // still point at it by foreign key, and that is what keeps a historical
        // day exactly as it was recorded.
        $current?->update(['status' => ProductVerificationStatus::Superseded]);
    }

    private function show(mixed $value): string
    {
        return $value === null || $value === '' ? '(none)' : (string) $value;
    }

    private function urlOrNull(?string $url): ?string
    {
        return $url !== null && mb_strlen($url) <= 255 ? $url : null;
    }

    private function limit(?string $value, int $max = 255): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }
}
