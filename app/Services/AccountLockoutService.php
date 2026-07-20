<?php

namespace App\Services;

use App\Models\User;

class AccountLockoutService
{
    /**
     * Default account-lockout values. Single source of truth so the
     * enforcement here and the Settings form stay in sync.
     */
    public const DEFAULT_MAX_ATTEMPTS = 5;

    public const DEFAULT_LOCKOUT_MINUTES = 15;

    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    /**
     * Consecutive failed login attempts allowed before the account is locked.
     */
    public function maxAttempts(): int
    {
        return (int) $this->settings->get('account_lockout_max_attempts', self::DEFAULT_MAX_ATTEMPTS);
    }

    /**
     * How long an account stays locked after hitting the attempt threshold.
     */
    public function lockoutMinutes(): int
    {
        return (int) $this->settings->get('account_lockout_minutes', self::DEFAULT_LOCKOUT_MINUTES);
    }

    /**
     * Whether the account is currently locked out.
     */
    public function isLocked(User $user): bool
    {
        return $user->locked_until !== null && $user->locked_until->isFuture();
    }

    /**
     * Record a failed login attempt, locking the account once the
     * configured threshold is reached.
     */
    public function recordFailedAttempt(User $user): void
    {
        if ($this->isLocked($user)) {
            return;
        }

        $user->failed_login_attempts++;

        if ($user->failed_login_attempts >= $this->maxAttempts()) {
            $user->locked_until = now()->addMinutes($this->lockoutMinutes());
        }

        $user->save();
    }

    /**
     * Clear the failed-attempt counter and any active lock, e.g. after a
     * successful login or an admin-initiated unlock.
     */
    public function resetAttempts(User $user): void
    {
        if ($user->failed_login_attempts === 0 && $user->locked_until === null) {
            return;
        }

        $user->failed_login_attempts = 0;
        $user->locked_until = null;
        $user->save();
    }

    /**
     * User-facing message describing how long the account remains locked.
     */
    public function lockoutMessage(User $user): string
    {
        return 'This account is locked due to too many failed login attempts. Try again '.$user->locked_until->diffForHumans().'.';
    }
}
