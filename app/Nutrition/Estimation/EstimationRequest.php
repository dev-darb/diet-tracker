<?php

namespace App\Nutrition\Estimation;

use App\Nutrition\MeasuredAmount;
use App\Nutrition\NutrientRegistry;
use App\ValueObjects\NutrientValues;
use InvalidArgumentException;

/**
 * A deliberate, bounded ask for an estimate.
 *
 * Every field here exists to stop the model being handed an open brief. It is
 * told exactly what food, exactly which nutrients, on exactly which basis, and
 * the caller has to have named a {@see EstimationReason} — there is no
 * constructor that means "have a look and see what you can fill in".
 *
 * The nutrient list in particular is required and non-empty. Anything the model
 * returns outside it is dropped, so an estimate can never quietly spread into
 * fields nobody asked about.
 */
final class EstimationRequest
{
    /**
     * @param  string  $subject  the food, in the words a person would use.
     * @param  array<int, string>  $nutrients  the nutrients being asked for.
     * @param  array<string, string>  $context  anything that narrows the guess:
     *                                          venue, brand, preparation, what else was on the plate.
     */
    private function __construct(
        public readonly string $subject,
        public readonly array $nutrients,
        public readonly EstimationReason $reason,
        public readonly EstimationBasis $basis,
        public readonly ?MeasuredAmount $portion,
        public readonly array $context,
    ) {}

    /**
     * @param  array<int, string>|null  $nutrients  defaults to the macros — the
     *                                              set a person actually reads. Micronutrients are
     *                                              estimable in principle but the guess is far
     *                                              weaker, so a caller has to ask for them by name.
     * @param  array<string, string>  $context
     *
     * @throws InvalidArgumentException when nothing usable was asked for.
     */
    public static function for(
        string $subject,
        EstimationReason $reason,
        EstimationBasis $basis = EstimationBasis::WholeItem,
        ?array $nutrients = null,
        ?MeasuredAmount $portion = null,
        array $context = [],
    ): self {
        $subject = trim($subject);

        if ($subject === '') {
            throw new InvalidArgumentException('An estimate needs a food to estimate.');
        }

        $nutrients = array_values(array_intersect(
            $nutrients ?? NutrientValues::MACRO_KEYS,
            NutrientRegistry::keys(),
        ));

        if ($nutrients === []) {
            throw new InvalidArgumentException(
                'An estimate must name the nutrients it wants. There is deliberately no way to ask for "whatever is missing".',
            );
        }

        return new self(
            subject: $subject,
            nutrients: $nutrients,
            reason: $reason,
            basis: $basis,
            portion: $portion,
            context: array_filter($context, static fn ($v): bool => is_string($v) && trim($v) !== ''),
        );
    }

    /** Whether this nutrient was asked for. Anything else the model offers is dropped. */
    public function wants(string $key): bool
    {
        return in_array($key, $this->nutrients, true);
    }

    /** The context as lines a prompt can carry. */
    public function contextLines(): string
    {
        $lines = [];

        foreach ($this->context as $label => $value) {
            $lines[] = ucfirst(str_replace('_', ' ', (string) $label)).': '.$value;
        }

        if ($this->portion !== null) {
            $lines[] = 'Portion: '.$this->portion->label();
        }

        return implode("\n", $lines);
    }

    /**
     * A compact record of what was asked, stored on the estimate so the request
     * is auditable alongside the answer.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'subject' => $this->subject,
            'reason' => $this->reason->value,
            'basis' => $this->basis->value,
            'nutrients' => $this->nutrients,
            'portion' => $this->portion?->label(),
            'context' => $this->context,
        ];
    }
}
