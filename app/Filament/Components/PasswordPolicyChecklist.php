<?php

namespace App\Filament\Components;

use App\Services\PasswordPolicyService;
use Filament\Schemas\Components\View;

/**
 * Builds the live password-policy checklist shown beneath a password field.
 *
 * Each enforced requirement is listed and evaluated client-side (Alpine) as the
 * user types — amber while unmet, green once satisfied. Placed as a sibling of a
 * password field bound to `$statePath` (default `data.password`).
 */
class PasswordPolicyChecklist
{
    public static function make(string $statePath = 'data.password'): View
    {
        $policy = app(PasswordPolicyService::class);

        return View::make('filament.components.password-policy-checklist')
            ->viewData([
                'statePath' => $statePath,
                'minLength' => $policy->minLength(),
                'requirements' => $policy->requirements(),
            ])
            ->visible(fn (): bool => $policy->requirements() !== []);
    }
}
