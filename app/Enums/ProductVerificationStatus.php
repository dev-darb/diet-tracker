<?php

namespace App\Enums;

/**
 * Lifecycle status of a product version's nutrition data (BUILD_PLAN §5, idea
 * #9; brief §10.2). A fuzzy match below threshold never silently becomes
 * canonical identity — it sits in `needs_review` until the validation layer or
 * an admin promotes it.
 */
enum ProductVerificationStatus: string
{
    case Pending = 'pending';
    case AutoVerified = 'auto_verified';
    case NeedsReview = 'needs_review';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::AutoVerified => 'Auto-verified',
            self::NeedsReview => 'Needs review',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
            self::Superseded => 'Superseded',
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => [
            'value' => $s->value,
            'label' => $s->label(),
        ], self::cases());
    }
}
