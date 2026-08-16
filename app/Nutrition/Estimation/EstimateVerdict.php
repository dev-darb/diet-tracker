<?php

namespace App\Nutrition\Estimation;

/**
 * The guard's ruling on a draft: what may be used, and what was thrown out.
 *
 * Only a verdict can be persisted — never a raw draft — so there is no code path
 * that writes a model's figures without them having been assessed first.
 */
final class EstimateVerdict
{
    /**
     * @param  array<string, float>  $values  the figures that may be used.
     * @param  array<int, string>  $notes  what was dropped or refused, and why.
     */
    private function __construct(
        public readonly bool $isAccepted,
        public readonly array $values,
        public readonly array $notes,
    ) {}

    /**
     * @param  array<string, float>  $values
     * @param  array<int, string>  $notes
     */
    public static function accepted(array $values, array $notes = []): self
    {
        return new self(true, $values, $notes);
    }

    /**
     * @param  array<int, string>  $notes
     */
    public static function rejected(array $notes): self
    {
        return new self(false, [], $notes);
    }

    /**
     * The nutrients this verdict actually supplies.
     *
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->values);
    }
}
