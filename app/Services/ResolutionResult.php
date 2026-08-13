<?php

namespace App\Services;

use App\Enums\ResolutionStatus;
use App\Models\CanonicalProduct;
use App\Models\ProductResolutionJob;

/**
 * The typed outcome of {@see ProductResolver::resolve()} (BUILD_PLAN J2.4).
 *
 * Carries the resolution status, the canonical product it resolved to (if any),
 * the confidence/score behind the decision, and the persisted
 * {@see ProductResolutionJob} audit row. For a {@see ResolutionStatus::Suggestion}
 * the `canonicalProduct` is populated but {@see isCanonicalIdentity()} is false —
 * callers must ask the user before treating a suggestion as the product's
 * identity (brief §7.2 Step 3, §7.6).
 */
final class ResolutionResult
{
    public function __construct(
        public readonly ResolutionStatus $status,
        public readonly ?CanonicalProduct $canonicalProduct,
        public readonly float $confidence,
        public readonly ProductResolutionJob $resolutionJob,
        public readonly ?float $matchScore = null,
    ) {}

    /** Whether this outcome may be treated as the product's canonical identity without asking the user. */
    public function isCanonicalIdentity(): bool
    {
        return $this->status->isMatch();
    }

    /** Whether this is a low-confidence suggestion the user must confirm. */
    public function isSuggestion(): bool
    {
        return $this->status === ResolutionStatus::Suggestion;
    }

    /** Whether the product is unknown and must go to research (Milestone 3). */
    public function needsResearch(): bool
    {
        return $this->status === ResolutionStatus::NeedsResearch;
    }
}
