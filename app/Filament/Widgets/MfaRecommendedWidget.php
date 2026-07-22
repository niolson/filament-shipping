<?php

namespace App\Filament\Widgets;

use App\Enums\Role;
use App\Services\SettingsService;
use Filament\Widgets\Widget;

/**
 * Nudges admins to turn on MFA while `require_mfa` is off. Admin accounts hold
 * high-privilege capabilities (raw-SQL data sources, carrier credentials,
 * cross-client data), so this surfaces the opt-in gap without forcing MFA — a
 * hard floor risks locking out a fresh install. See security review issue 13.
 */
class MfaRecommendedWidget extends Widget
{
    protected static ?int $sort = -9;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.mfa-recommended';

    protected ?string $pollingInterval = null;

    /**
     * Hides the widget immediately after it is dismissed, without a page reload.
     * `canView()` keeps it hidden on subsequent loads via the persisted setting.
     */
    public bool $dismissed = false;

    public static function canView(): bool
    {
        $settings = app(SettingsService::class);

        if ((bool) $settings->get('require_mfa', false)) {
            return false;
        }

        if ((bool) $settings->get('mfa_recommendation_dismissed', false)) {
            return false;
        }

        // Only admins can turn MFA enforcement on or off, so only they see the nudge.
        $role = auth()->user()?->getAttribute('role');

        return $role instanceof Role && $role->isAtLeast(Role::Admin);
    }

    /**
     * Persist the dismissal app-wide so the nudge does not return for any admin.
     */
    public function dismiss(): void
    {
        app(SettingsService::class)->set('mfa_recommendation_dismissed', true, 'boolean');

        $this->dismissed = true;
    }
}
