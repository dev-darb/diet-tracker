<?php

namespace App\Enums;

/**
 * The user's primary reason for using the app (brief §6.3).
 *
 * This is the only required onboarding field. Everything else is optional and
 * only exists to personalise later nutrition guidance.
 */
enum PrimaryGoal: string
{
    case EatHealthier = 'eat_healthier';
    case UnderstandDiet = 'understand_diet';
    case MaintainWeight = 'maintain_weight';
    case LoseWeight = 'lose_weight';
    case GainMuscle = 'gain_muscle';
    case Recomp = 'recomp';

    public function label(): string
    {
        return match ($this) {
            self::EatHealthier => 'Eat healthier',
            self::UnderstandDiet => 'Understand my diet',
            self::MaintainWeight => 'Maintain my weight',
            self::LoseWeight => 'Lose weight',
            self::GainMuscle => 'Gain weight or muscle',
            self::Recomp => 'Build muscle, lose fat',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::EatHealthier => 'General guidance to improve the overall quality of what you eat.',
            self::UnderstandDiet => 'See clearly what your diet actually looks like, without judgement.',
            self::MaintainWeight => 'Keep things steady and balanced.',
            self::LoseWeight => 'Gentle, sustainable guidance towards a lower weight.',
            self::GainMuscle => 'Support for building muscle and gaining weight.',
            self::Recomp => 'Recomposition: train at maintenance with high protein.',
        };
    }

    /** @return array<int, array{value: string, label: string, description: string}> */
    public static function options(): array
    {
        return array_map(fn (self $g) => [
            'value' => $g->value,
            'label' => $g->label(),
            'description' => $g->description(),
        ], self::cases());
    }
}
