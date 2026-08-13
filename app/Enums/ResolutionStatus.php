<?php

namespace App\Enums;

/**
 * Outcome of a product resolution attempt (BUILD_PLAN J2.4; brief §7.2 Step 3,
 * §10.2). Ordered by identifier strength: barcode is the strongest signal,
 * needs_research the weakest. Stored on `product_resolution_jobs.status`.
 *
 * A `suggestion` is deliberately distinct from a match: a fuzzy hit below the
 * auto-match threshold is surfaced as a low-confidence suggestion and MUST NOT
 * silently become canonical identity (brief §7.2 Step 3).
 */
enum ResolutionStatus: string
{
    /** Exact GTIN/barcode match (local, or freshly imported from Open Food Facts). */
    case MatchedBarcode = 'matched_barcode';

    /** Exact brand + name + variant + pack-size match against a local canonical product. */
    case MatchedExact = 'matched_exact';

    /** Fuzzy match at or above the auto-match confidence threshold. */
    case MatchedFuzzy = 'matched_fuzzy';

    /** Fuzzy hit below auto-match but above the suggestion floor — a suggestion only, not identity. */
    case Suggestion = 'suggestion';

    /** No acceptable match; the product must go through research (Milestone 3). */
    case NeedsResearch = 'needs_research';

    public function isMatch(): bool
    {
        return match ($this) {
            self::MatchedBarcode, self::MatchedExact, self::MatchedFuzzy => true,
            self::Suggestion, self::NeedsResearch => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::MatchedBarcode => 'Matched by barcode',
            self::MatchedExact => 'Exact match',
            self::MatchedFuzzy => 'Fuzzy match',
            self::Suggestion => 'Low-confidence suggestion',
            self::NeedsResearch => 'Needs research',
        };
    }
}
