<?php

namespace App\Services;

use App\Models\User;

class MfaResetService
{
    public function hasAppAuthentication(User $user): bool
    {
        return filled($user->app_authentication_secret);
    }

    public function hasEmailAuthentication(User $user): bool
    {
        return $user->hasEmailAuthentication();
    }

    /**
     * Clear the authenticator app secret and recovery codes, e.g. after a
     * user loses their device. They will need to re-enroll from scratch.
     */
    public function resetAppAuthentication(User $user): void
    {
        $user->app_authentication_secret = null;
        $user->app_authentication_recovery_codes = null;
        $user->save();
    }

    /**
     * Disable email-code authentication, e.g. after a user loses access to
     * their email inbox. They will need to re-enable it from their profile.
     */
    public function resetEmailAuthentication(User $user): void
    {
        $user->has_email_authentication = false;
        $user->save();
    }
}
