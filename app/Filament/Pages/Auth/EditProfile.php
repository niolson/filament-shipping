<?php

namespace App\Filament\Pages\Auth;

use App\Filament\Components\PasswordPolicyChecklist;
use App\Models\User;
use App\Services\PasswordPolicyService;
use Closure;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

class EditProfile extends BaseEditProfile
{
    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        $this->getFormContentComponent(),
                        ...Arr::wrap($this->getMultiFactorAuthenticationContentComponent()),
                    ]),
            ]);
    }

    protected function getPasswordFormComponent(): Component
    {
        $policy = app(PasswordPolicyService::class);
        $user = $this->getUser();

        return TextInput::make('password')
            ->label(__('filament-panels::auth/pages/edit-profile.form.password.label'))
            ->validationAttribute(__('filament-panels::auth/pages/edit-profile.form.password.validation_attribute'))
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->rules([
                $policy->rule(),
                fn (): Closure => $policy->historyRule($user instanceof User ? $user : null),
                fn (): Closure => $policy->minimumAgeRule($user instanceof User ? $user : null),
            ])
            ->showAllValidationMessages()
            ->autocomplete('new-password')
            ->dehydrated(fn (#[SensitiveParameter] ?string $state): bool => filled($state))
            ->dehydrateStateUsing(fn (#[SensitiveParameter] string $state): string => Hash::make($state))
            ->live(debounce: 500)
            ->belowContent(PasswordPolicyChecklist::make())
            ->same('passwordConfirmation');
    }
}
