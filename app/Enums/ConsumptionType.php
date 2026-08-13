<?php

namespace App\Enums;

/**
 * Whether a consumption event is a single product or a multi-ingredient meal
 * (BUILD_PLAN §5; brief §8.1/§8.4).
 */
enum ConsumptionType: string
{
    case Single = 'single';
    case Meal = 'meal';
}
