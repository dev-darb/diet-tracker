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
}
