<?php

namespace App\Filament\Auth;

use Filament\Auth\MultiFactor\Email\EmailAuthentication;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Auth\Authenticatable;

class EmailAuthenticationForEmailUsers extends EmailAuthentication
{
    public function isEnabled(Authenticatable $user): bool
    {
        return filled($user->email) && parent::isEnabled($user);
    }

    /**
     * @return array<Component>
     */
    public function getManagementSchemaComponents(): array
    {
        if (! filled(Filament::auth()->user()?->email)) {
            return [];
        }

        return parent::getManagementSchemaComponents();
    }
}
