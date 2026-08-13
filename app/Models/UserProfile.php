<?php

namespace App\Models;

use App\Enums\ActivityLevel;
use App\Enums\DietaryPattern;
use App\Enums\PrimaryGoal;
use App\Enums\Sex;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProfile extends Model
{
    /** @use HasFactory<\Database\Factories\UserProfileFactory> */
    use HasFactory;

    protected $fillable = [
        'primary_goal',
        'date_of_birth',
        'sex',
        'height_cm',
        'weight_kg',
        'activity_level',
        'dietary_pattern',
        'dietary_preferences',
        'allergies',
        'avoided_foods',
        'onboarding_completed_at',
    ];

    protected function casts(): array
    {
        return [
            'primary_goal' => PrimaryGoal::class,
            'sex' => Sex::class,
            'activity_level' => ActivityLevel::class,
            'dietary_pattern' => DietaryPattern::class,
            'date_of_birth' => 'date',
            'height_cm' => 'integer',
            'weight_kg' => 'decimal:2',
            'dietary_preferences' => 'array',
            'allergies' => 'array',
            'avoided_foods' => 'array',
            'onboarding_completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasCompletedOnboarding(): bool
    {
        return $this->onboarding_completed_at !== null;
    }
}
