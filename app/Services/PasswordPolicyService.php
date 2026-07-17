<?php

namespace App\Services;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Hash;
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

    public const DEFAULT_HISTORY_COUNT = 10;

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
     * How many of a user's most recent passwords (including their current one)
     * may not be reused.
     */
    public function historyCount(): int
    {
        return (int) $this->settings->get('password_history_count', self::DEFAULT_HISTORY_COUNT);
    }

    /**
     * Whether the given plaintext password matches the user's current password
     * or one of their recent previous passwords.
     */
    public function isPasswordReused(User $user, string $password): bool
    {
        $limit = $this->historyCount();

        if ($limit <= 0) {
            return false;
        }

        if ($user->password !== null && Hash::check($password, $user->password)) {
            return true;
        }

        foreach ($user->passwordHistories()->limit($limit - 1)->get() as $history) {
            if (Hash::check($password, $history->password_hash)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A raw Laravel-style ($attribute, $value, $fail) validation rule closure
     * that rejects a password reused from the given user's recent history.
     * Pass null when there is no existing user (e.g. creating a brand-new
     * account), in which case history is skipped.
     *
     * Filament's field ->rules() eagerly evaluates (and DI-resolves the
     * parameters of) any closure passed directly as a rule *item*, so when
     * placing this in a literal rules array, wrap it first: `fn () =>
     * $policy->historyRule($user)`. When it's returned from within an
     * already-evaluated closure (e.g. building the rules array off an
     * injected `$record`), pass it through as-is — it won't be evaluated
     * again.
     */
    public function historyRule(?User $user): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($user): void {
            if ($user !== null && is_string($value) && $this->isPasswordReused($user, $value)) {
                $fail('You cannot reuse a recent password. Please choose a different one.');
            }
        };
    }

    /**
     * Trim a user's stored password history down to the configured limit.
     */
    public function pruneHistory(User $user): void
    {
        $limit = max(0, $this->historyCount() - 1);

        $keepIds = $user->passwordHistories()->take($limit)->pluck('id');

        $user->passwordHistories()->whereNotIn('id', $keepIds)->delete();
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
