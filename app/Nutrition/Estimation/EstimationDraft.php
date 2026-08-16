<?php

namespace App\Nutrition\Estimation;

/**
 * What a model proposed, before any of it has been accepted.
 *
 * A draft is not an estimate. It is a claim plus its working, and it has to get
 * past {@see EstimateGuard} before a single figure of it reaches a nutrition
 * row. Keeping the two types distinct means there is no way to accidentally
 * persist a raw model response — the persisting code only takes a verdict.
 *
 * The working matters as much as the figures. A number with no reasoning behind
 * it cannot be checked by anyone later, so a draft without steps is rejected on
 * principle rather than merely distrusted.
 */
final class EstimationDraft
{
    /**
     * @param  array<string, float|null>  $values  the proposed figures.
     * @param  array<int, string>  $steps  the reasoning, in order.
     * @param  array<int, string>  $assumptions  what had to be assumed to get there.
     */
    public function __construct(
        public readonly array $values,
        public readonly array $steps,
        public readonly array $assumptions,
        public readonly ?string $reference,
        public readonly float $confidence,
    ) {}

    /**
     * @param  array<string, mixed>  $data  the model's structured response.
     */
    public static function fromArray(array $data): self
    {
        $values = [];

        foreach ((array) ($data['values'] ?? []) as $key => $value) {
            if (is_string($key) && ($value === null || is_numeric($value))) {
                $values[$key] = $value === null ? null : (float) $value;
            }
        }

        return new self(
            values: $values,
            steps: self::lines($data['steps'] ?? []),
            assumptions: self::lines($data['assumptions'] ?? []),
            reference: self::text($data['reference'] ?? null),
            confidence: max(0.0, min(1.0, is_numeric($data['confidence'] ?? null) ? (float) $data['confidence'] : 0.0)),
        );
    }

    /**
     * @return array<int, string>
     */
    private static function lines(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $lines = [];

        foreach ($raw as $line) {
            $line = self::text($line);

            if ($line !== null) {
                $lines[] = mb_substr($line, 0, 240);
            }
        }

        return array_slice($lines, 0, 12);
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
