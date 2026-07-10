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

    public static function canView(): bool
    {
        if ((bool) app(SettingsService::class)->get('require_mfa', false)) {
            return false;
        }

        $role = auth()->user()?->getAttribute('role');

        return $role instanceof Role && $role->isAtLeast(Role::Admin);
    }
}
