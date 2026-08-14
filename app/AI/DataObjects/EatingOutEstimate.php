<?php

namespace App\AI\DataObjects;

use App\AI\Contracts\EatingOutEstimator;

/**
 * An estimated nutritional picture of a restaurant/cafe/takeaway dish
 * (capture-flow Phase B; BUILD_PLAN §1b tier 3). Produced by an
 * {@see EatingOutEstimator} from the dish name + venue —
 * typical-composition knowledge, published chain-menu data when the venue is
 * known. Figures are ESTIMATES: nullable per nutrient (unknown stays unknown,
 * never fabricated), carried with an honest confidence and a short basis the
 * user can judge ("Wagamama publishes chicken katsu curry at ~1180 kcal").
 *
 * This object is presentation/proposal only — the user confirms (and can edit)
 * before anything is written, and what is written is marked `estimated`.
 */
final class EatingOutEstimate
{
    public function __construct(
        public readonly ?float $calories,
        public readonly ?float $protein,
        public readonly ?float $carbs,
        public readonly ?float $sugars,
        public readonly ?float $fat,
        public readonly ?float $saturatedFat,
        public readonly ?float $fibre,
        public readonly ?float $salt,
        public readonly float $confidence,
        public readonly ?string $basis,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $figure = static function (mixed $value): ?float {
            if ($value === null || ! is_numeric($value)) {
                return null;
            }

            $value = (float) $value;

            return ($value < 0 || $value > 99999) ? null : round($value, 1);
        };

        $confidence = is_numeric($data['confidence'] ?? null) ? (float) $data['confidence'] : 0.0;

        return new self(
            calories: $figure($data['calories'] ?? null),
            protein: $figure($data['protein'] ?? null),
            carbs: $figure($data['carbs'] ?? null),
            sugars: $figure($data['sugars'] ?? null),
            fat: $figure($data['fat'] ?? null),
            saturatedFat: $figure($data['saturated_fat'] ?? null),
            fibre: $figure($data['fibre'] ?? null),
            salt: $figure($data['salt'] ?? null),
            confidence: max(0.0, min(1.0, $confidence)),
            basis: is_string($data['basis'] ?? null) && trim($data['basis']) !== '' ? trim($data['basis']) : null,
        );
    }

    /** Keyed like consumption figure columns, ready for logEatingOut(). */
    public function figures(): array
    {
        return [
            'calories' => $this->calories,
            'protein' => $this->protein,
            'carbs' => $this->carbs,
            'sugars' => $this->sugars,
            'fat' => $this->fat,
            'saturated_fat' => $this->saturatedFat,
            'fibre' => $this->fibre,
            'salt' => $this->salt,
        ];
    }

    public function hasFigures(): bool
    {
        return array_filter($this->figures(), static fn (?float $v) => $v !== null) !== [];
    }
}
