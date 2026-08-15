<?php

namespace App\AI\DataObjects;

use App\Services\ProductResolver;

/**
 * The typed result of visual identification (brief §7.2 Step 2; unified
 * capture, Aug 2026).
 *
 * Mirrors the structured shape the model returns:
 * `{kind, brand, product_name, variant, pack_size, barcode, dish_name,
 * confidence}`. The model first decides WHAT it is looking at (`kind`:
 * packaged product / loose ingredient / prepared meal / unknown), then
 * extracts identity fields for the product kinds or a dish name for meals.
 * Every identity field is nullable — the model may not see a brand, a
 * variant, or a barcode — while `confidence` is always present (defaulting
 * to 0.0 when omitted). This is a pure DTO; it holds no provider types.
 */
final class IdentifiedProduct
{
    public function __construct(
        public readonly ?string $brand,
        public readonly ?string $productName,
        public readonly ?string $variant,
        public readonly ?string $packSize,
        public readonly ?string $barcode,
        public readonly float $confidence,
        public readonly ?string $kind = null,
        public readonly ?string $dishName = null,
    ) {}

    /**
     * Build from the model's structured array, tolerating missing keys and
     * blank strings (which become null). Confidence is clamped to [0, 1].
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            brand: self::string($data['brand'] ?? null),
            productName: self::string($data['product_name'] ?? null),
            variant: self::string($data['variant'] ?? null),
            packSize: self::string($data['pack_size'] ?? null),
            barcode: self::string($data['barcode'] ?? null),
            confidence: self::confidence($data['confidence'] ?? null),
            kind: self::string($data['kind'] ?? null),
            dishName: self::string($data['dish_name'] ?? null),
        );
    }

    /** Whether the model produced any usable identity (brand or product name). */
    public function hasIdentity(): bool
    {
        return $this->brand !== null || $this->productName !== null;
    }

    /**
     * The identity fields as an array suitable for a product_resolution_jobs
     * `detected_fields` column and for {@see ProductResolver}.
     *
     * @return array<string, string|float|null>
     */
    public function toArray(): array
    {
        return [
            'brand' => $this->brand,
            'product_name' => $this->productName,
            'variant' => $this->variant,
            'pack_size' => $this->packSize,
            'barcode' => $this->barcode,
            'confidence' => $this->confidence,
        ];
    }

    private static function string(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function confidence(mixed $value): float
    {
        if ($value === null || ! is_numeric($value)) {
            return 0.0;
        }

        return max(0.0, min(1.0, (float) $value));
    }
}
