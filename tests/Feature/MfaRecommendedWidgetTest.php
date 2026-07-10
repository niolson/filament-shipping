<?php

use App\Enums\Role;
use App\Filament\Widgets\MfaRecommendedWidget;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows the MFA nudge to admins while require_mfa is off', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    expect(MfaRecommendedWidget::canView())->toBeTrue();
});

it('hides the MFA nudge once require_mfa is on', function (): void {
    app(SettingsService::class)->set('require_mfa', true, 'boolean');
    app(SettingsService::class)->clearCache();

    $this->actingAs(User::factory()->admin()->create());

    expect(MfaRecommendedWidget::canView())->toBeFalse();
});

it('hides the MFA nudge from non-admins', function (): void {
    $this->actingAs(User::factory()->create(['role' => Role::User]));

    expect(MfaRecommendedWidget::canView())->toBeFalse();
});
