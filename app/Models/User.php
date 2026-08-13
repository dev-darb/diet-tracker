<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable // implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * The user's one-to-one nutrition/health profile (brief §6.5).
     */
    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    /** The user's pantry stock (BUILD_PLAN §5, idea #5). @return HasMany<PantryItem, $this> */
    public function pantryItems(): HasMany
    {
        return $this->hasMany(PantryItem::class);
    }

    /** The user's logged eating events (brief §8.1). @return HasMany<ConsumptionEvent, $this> */
    public function consumptionEvents(): HasMany
    {
        return $this->hasMany(ConsumptionEvent::class);
    }

    /** The user's generated insights (brief §9.6). @return HasMany<AiInsight, $this> */
    public function aiInsights(): HasMany
    {
        return $this->hasMany(AiInsight::class);
    }

    /**
     * Whether the user has finished the onboarding flow (brief §6.3/§6.4).
     */
    public function hasCompletedOnboarding(): bool
    {
        return (bool) $this->profile?->hasCompletedOnboarding();
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->map(fn (string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }
}
