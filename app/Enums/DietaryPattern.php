<?php

namespace App\Enums;

enum DietaryPattern: string
{
    case Omnivore = 'omnivore';
    case Flexitarian = 'flexitarian';
    case Pescatarian = 'pescatarian';
    case Vegetarian = 'vegetarian';
    case Vegan = 'vegan';

    public function label(): string
    {
        return match ($this) {
            self::Omnivore => 'No restrictions',
            self::Flexitarian => 'Flexitarian',
            self::Pescatarian => 'Pescatarian',
            self::Vegetarian => 'Vegetarian',
            self::Vegan => 'Vegan',
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $p) => ['value' => $p->value, 'label' => $p->label()], self::cases());
    }
}
