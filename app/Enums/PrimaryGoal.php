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
    case Performance = 'performance';
    case GutHealth = 'gut_health';

    public function label(): string
    {
        return match ($this) {
            self::EatHealthier => 'Eat healthier',
            self::UnderstandDiet => 'Understand my diet',
            self::MaintainWeight => 'Maintain my weight',
            self::LoseWeight => 'Lose weight',
            self::GainMuscle => 'Gain weight or muscle',
            self::Recomp => 'Build muscle, lose fat',
            self::Performance => 'Perform and endure',
            self::GutHealth => 'Look after my gut',
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
            self::Performance => 'Fuel training and endurance with carbs as a priority.',
            self::GutHealth => 'Fibre, plants and variety take the front seat.',
        };
    }

    /**
     * The Foody Score goal-profile key this goal scores under (spec §4).
     * Several product goals share a scoring profile; the profile decides
     * pillar weights, curves and macro subweights in config/foody_score.php.
     */
    public function scoreProfile(): string
    {
        return match ($this) {
            self::EatHealthier, self::UnderstandDiet, self::MaintainWeight => 'general_health',
            self::LoseWeight => 'fat_loss',
            self::GainMuscle => 'muscle_gain',
            self::Recomp => 'recomp',
            self::Performance => 'performance',
            self::GutHealth => 'gut_health',
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
