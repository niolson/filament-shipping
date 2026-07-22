<?php

namespace App\Filament\Pages\Auth;

use App\Services\PasswordPolicyService;
use BackedEnum;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

class ChangePassword extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'auth/change-password';

    protected static ?string $title = 'Change Password';

    protected string $view = 'filament.pages.auth.change-password';

    public ?array $data = [];

    public function mount(): void
    {
        if (! session('password_expired')) {
            $this->redirect('/');

            return;
        }

        $this->form->fill();
    }

    public function form(Schema $form): Schema
    {
        $policy = app(PasswordPolicyService::class);
        $user = auth()->user();

        return $form
            ->schema([
                TextInput::make('current_password')
                    ->label('Current Password')
                    ->password()
                    ->required()
                    ->rules(['current_password']),
                TextInput::make('password')
                    ->label('New Password')
                    ->password()
                    ->required()
                    ->rules(['confirmed', $policy->rule(), fn (): Closure => $policy->historyRule($user)])
                    ->different('current_password'),
                TextInput::make('password_confirmation')
                    ->label('Confirm New Password')
                    ->password()
                    ->required(),
            ])
            ->statePath('data');
    }

    public function changePassword(): void
    {
        $data = $this->form->getState();

        $user = auth()->user();
        $user->password = Hash::make($data['password']);
        $user->save();

        // Keep the current session authenticated. The panel runs AuthenticateSession,
        // which logs the user out on the next request when the stored password hash no
        // longer matches. Re-store the new hash so the change doesn't bounce them to login.
        session()->put('password_hash_'.Filament::getAuthGuard(), $user->getAuthPassword());

        session()->forget('password_expired');

        Notification::make()
            ->success()
            ->title('Password changed successfully.')
            ->send();

        $this->redirect('/');
    }
}
