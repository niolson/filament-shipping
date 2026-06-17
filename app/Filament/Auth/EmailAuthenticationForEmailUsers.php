<?php

namespace App\Filament\Auth;

use App\Models\User;
use Filament\Auth\MultiFactor\Email\EmailAuthentication;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Auth\Authenticatable;

class EmailAuthenticationForEmailUsers extends EmailAuthentication
{
    public function isEnabled(Authenticatable $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return filled($user->email) && parent::isEnabled($user);
    }

    /**
     * @return array<Component>
     */
    public function getManagementSchemaComponents(): array
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User || ! filled($user->email)) {
            return [];
        }

        return parent::getManagementSchemaComponents();
    }
}
