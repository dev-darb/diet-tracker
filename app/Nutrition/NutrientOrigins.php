<?php

namespace App\Nutrition;

/**
 * The per-nutrient provenance map that rides alongside a set of figures.
 *
 * Compact by design: only nutrients whose origin is NOT {@see NutrientOrigin::Stated}
 * appear, because "a source said so" is the ordinary case and recording it on
 * every product would triple the size of every nutrition row to say nothing.
 *
 * Serialises to `{"calories": "derived:energy-kj_100g", "fibre": "estimated"}`.
 */
final class NutrientOrigins
{
    /**
     * @param  array<string, string>  $map  nutrient key => "<origin>" or "<origin>:<detail>"
     */
    private function __construct(private readonly array $map) {}

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param  array<string, string>|null  $map
     */
    public static function fromArray(?array $map): self
    {
        if ($map === null) {
            return self::none();
        }

        $clean = [];

        foreach ($map as $key => $value) {
            if (! is_string($key) || ! is_string($value) || $value === '') {
                continue;
            }

            // Ignore anything that is not a real origin — a stale or hand-edited
            // map must degrade to "stated", never to a marker nobody can explain.
            if (NutrientOrigin::tryFrom(explode(':', $value)[0]) !== null) {
                $clean[$key] = $value;
            }
        }

        return new self($clean);
    }

    /**
     * Mark a set of nutrients with one origin, returning a new map.
     *
     * @param  array<int, string>  $keys
     */
    public function mark(array $keys, NutrientOrigin $origin, ?string $detail = null): self
    {
        $map = $this->map;
        $value = $detail !== null && $detail !== '' ? "{$origin->value}:{$detail}" : $origin->value;

        foreach ($keys as $key) {
            // Stated is the implicit default; writing it would only add noise.
            if ($origin === NutrientOrigin::Stated) {
                unset($map[$key]);

                continue;
            }

            $map[$key] = $value;
        }

        return new self($map);
    }

    /**
     * Mark each key with its own detail — the shape the Open Food Facts reader
     * produces, where every derived figure names the field it came from.
     *
     * @param  array<string, string>  $details  nutrient key => detail
     */
    public function markEach(array $details, NutrientOrigin $origin): self
    {
        $out = $this;

        foreach ($details as $key => $detail) {
            $out = $out->mark([$key], $origin, $detail);
        }

        return $out;
    }

    public function originOf(string $key): NutrientOrigin
    {
        $raw = $this->map[$key] ?? null;

        return $raw === null
            ? NutrientOrigin::Stated
            : NutrientOrigin::tryFrom(explode(':', $raw)[0]) ?? NutrientOrigin::Stated;
    }

    /** The extra note behind an origin, e.g. which source field a figure was converted from. */
    public function detailOf(string $key): ?string
    {
        $raw = $this->map[$key] ?? null;

        if ($raw === null || ! str_contains($raw, ':')) {
            return null;
        }

        return explode(':', $raw, 2)[1] ?: null;
    }

    /**
     * Nutrients carrying a given origin.
     *
     * @return array<int, string>
     */
    public function keysWith(NutrientOrigin $origin): array
    {
        return array_values(array_keys(array_filter(
            $this->map,
            fn (string $raw): bool => NutrientOrigin::tryFrom(explode(':', $raw)[0]) === $origin,
        )));
    }

    /** Whether any figure here was produced by a model rather than a source. */
    public function hasEstimates(): bool
    {
        return $this->keysWith(NutrientOrigin::Estimated) !== [];
    }

    public function isEmpty(): bool
    {
        return $this->map === [];
    }

    /**
     * For persistence. Null when nothing but stated figures are present, so the
     * column stays empty on the overwhelming majority of rows.
     *
     * @return array<string, string>|null
     */
    public function toArray(): ?array
    {
        return $this->map === [] ? null : $this->map;
    }
}
