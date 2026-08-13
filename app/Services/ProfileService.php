<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Support\Facades\DB;

/**
 * All profile / onboarding / account domain logic lives here so the Livewire
 * components stay thin and a different frontend could reuse it later
 * (brief §4.1, §22.14; BUILD_PLAN architecture rules).
 *
 * No AI is involved anywhere in this milestone (brief §6.6).
 */
class ProfileService
{
    /**
     * Persist the onboarding answers and mark onboarding complete.
     *
     * @param  array<string, mixed>  $data
     */
    public function completeOnboarding(User $user, array $data): UserProfile
    {
        $profile = $user->profile()->firstOrNew();

        $profile->fill($this->sanitise($data));
        $profile->onboarding_completed_at ??= now();
        $profile->save();

        return $profile->refresh();
    }

    /**
     * Update an existing profile's fields (edit-later flow, brief §6.4).
     *
     * @param  array<string, mixed>  $data
     */
    public function updateProfile(User $user, array $data): UserProfile
    {
        $profile = $user->profile()->firstOrNew();
        $profile->fill($this->sanitise($data));
        $profile->save();

        return $profile->refresh();
    }

    /**
     * Update the core account fields on the user record itself.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateAccount(User $user, array $data): User
    {
        $user->fill(array_intersect_key($data, array_flip(['name', 'email'])));

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return $user;
    }

    /**
     * Permanently delete the user and all of their data (brief §6.4, §21 Q45).
     *
     * The user_profiles FK is ON DELETE CASCADE, so deleting the user removes
     * the profile in the same database transaction.
     */
    public function deleteAccount(User $user): void
    {
        DB::transaction(function () use ($user) {
            // Explicit for clarity and driver independence; the FK is also
            // ON DELETE CASCADE so this stays correct if relations grow.
            $user->profile()->delete();
            $user->delete();
        });
    }

    /**
     * Normalise the incoming payload: empty strings/arrays become null so we
     * never store meaningless blanks for optional fields.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitise(array $data): array
    {
        $allowed = [
            'primary_goal', 'date_of_birth', 'sex', 'height_cm', 'weight_kg',
            'activity_level', 'dietary_pattern', 'dietary_preferences',
            'allergies', 'avoided_foods',
        ];

        $clean = [];

        foreach (array_intersect_key($data, array_flip($allowed)) as $key => $value) {
            if ($value === '' || $value === []) {
                $value = null;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }
}
