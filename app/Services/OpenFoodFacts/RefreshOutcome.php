<?php

namespace App\Services\OpenFoodFacts;

/**
 * What happened when one product was walked back through the importer.
 *
 * @see ProductRefresher
 */
final class RefreshOutcome
{
    /**
     * @param  list<string>  $changes  human-readable descriptions of what moved.
     */
    private function __construct(
        public readonly bool $reachable,
        public readonly bool $identityChanged,
        public readonly bool $nutritionChanged,
        public readonly array $changes = [],
    ) {}

    public static function unreachable(): self
    {
        return new self(reachable: false, identityChanged: false, nutritionChanged: false);
    }

    /**
     * @param  list<string>  $changes
     */
    public static function changed(bool $identity, bool $nutrition, array $changes): self
    {
        return new self(reachable: true, identityChanged: $identity, nutritionChanged: $nutrition, changes: $changes);
    }

    public static function unchanged(): self
    {
        return new self(reachable: true, identityChanged: false, nutritionChanged: false);
    }

    /** Which bucket this falls into for the command's summary table. */
    public function summaryKey(): string
    {
        return match (true) {
            ! $this->reachable => 'unreachable',
            $this->nutritionChanged => 'nutrition',
            $this->identityChanged => 'identity',
            default => 'unchanged',
        };
    }
}
