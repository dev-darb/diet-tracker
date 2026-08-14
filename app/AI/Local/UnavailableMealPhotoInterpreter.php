<?php

namespace App\AI\Local;

use App\AI\Contracts\MealPhotoInterpreter;
use App\AI\DataObjects\MealPhotoReading;
use App\AI\DataObjects\ProductImage;

/**
 * KEY-ABSENT GRACE for meal-photo interpretation: with no AI gateway key the
 * capture flow simply doesn't offer the photo shortcut — manual compose and
 * manual eating-out entry keep working. Photos are an upgrade, never a
 * requirement.
 */
class UnavailableMealPhotoInterpreter implements MealPhotoInterpreter
{
    public function available(): bool
    {
        return false;
    }

    public function interpret(ProductImage $photo, array $pantryCandidates = []): ?MealPhotoReading
    {
        return null;
    }
}
