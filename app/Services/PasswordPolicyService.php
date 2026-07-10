<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Validation\Rules\Password;

class PasswordPolicyService
{
    /**
     * Default password-policy values. Single source of truth so the enforcement
     * here and the Settings form stay in sync. Tightened baseline (issue 13):
     * 12-char minimum, symbols required, and a 90-day expiration window.
     */
    public const DEFAULT_MIN_LENGTH = 12;

    public const DEFAULT_REQUIRE_MIXED_CASE = true;

    public const DEFAULT_REQUIRE_NUMBERS = true;

    public const DEFAULT_REQUIRE_SYMBOLS = true;

    public const DEFAULT_EXPIRATION_DAYS = 90;

    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    /**
     * Build a Password validation rule based on configured policy.
     */
    public function rule(): Password
    {
        $rule = Password::min((int) $this->settings->get('password_min_length', self::DEFAULT_MIN_LENGTH));

        if ($this->settings->get('password_require_mixed_case', self::DEFAULT_REQUIRE_MIXED_CASE)) {
            $rule->mixedCase();
        }

        if ($this->settings->get('password_require_numbers', self::DEFAULT_REQUIRE_NUMBERS)) {
            $rule->numbers();
        }

        if ($this->settings->get('password_require_symbols', self::DEFAULT_REQUIRE_SYMBOLS)) {
            $rule->symbols();
        }

        return $rule;
    }

    /**
     * Check if a user's password has expired.
     */
    public function isPasswordExpired(User $user): bool
    {
        $expirationDays = (int) $this->settings->get('password_expiration_days', self::DEFAULT_EXPIRATION_DAYS);

        if ($expirationDays === 0) {
            return false;
        }

        if (! $user->hasLocalPassword()) {
            return false;
        }

        if (! $user->password_changed_at) {
            // Never set — treat as expired to force initial password change
            return true;
        }

        return $user->password_changed_at->addDays($expirationDays)->isPast();
    }
}
