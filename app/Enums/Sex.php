<?php

namespace App\Enums;

enum Sex: string
{
    case Female = 'female';
    case Male = 'male';
    case Other = 'other';
    case PreferNotToSay = 'prefer_not_to_say';

    public function label(): string
    {
        return match ($this) {
            self::Female => 'Female',
            self::Male => 'Male',
            self::Other => 'Other',
            self::PreferNotToSay => 'Prefer not to say',
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
