<?php

namespace App\Enums;

enum ActivityLevel: string
{
    case Sedentary = 'sedentary';
    case Light = 'light';
    case Moderate = 'moderate';
    case Active = 'active';
    case VeryActive = 'very_active';

    public function label(): string
    {
        return match ($this) {
            self::Sedentary => 'Sedentary',
            self::Light => 'Lightly active',
            self::Moderate => 'Moderately active',
            self::Active => 'Active',
            self::VeryActive => 'Very active',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Sedentary => 'Little or no exercise, mostly sitting.',
            self::Light => 'Light exercise 1–3 days a week.',
            self::Moderate => 'Moderate exercise 3–5 days a week.',
            self::Active => 'Hard exercise 6–7 days a week.',
            self::VeryActive => 'Very hard exercise or a physical job.',
        };
    }

    /** @return array<int, array{value: string, label: string, description: string}> */
    public static function options(): array
    {
        return array_map(fn (self $a) => [
            'value' => $a->value,
            'label' => $a->label(),
            'description' => $a->description(),
        ], self::cases());
    }
}
