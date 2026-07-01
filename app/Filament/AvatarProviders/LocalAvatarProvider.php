<?php

namespace App\Filament\AvatarProviders;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

class LocalAvatarProvider implements AvatarProvider
{
    /**
     * Render an initials avatar as an inline SVG data URI.
     *
     * Replaces Filament's default UiAvatarsProvider (which calls out to
     * ui-avatars.com), so no third-party request is made and the
     * Content-Security-Policy needs no external image origin — the existing
     * `img-src data:` allowance covers it.
     */
    public function get(Model|Authenticatable $record): string
    {
        $name = trim(Filament::getNameForDefaultAvatar($record));

        $initials = str($name)
            ->explode(' ')
            ->map(fn (string $segment): string => mb_substr($segment, 0, 1))
            ->filter()
            ->take(2)
            ->implode('');

        $initials = mb_strtoupper($initials === '' ? '?' : $initials);
        $initials = htmlspecialchars($initials, ENT_QUOTES | ENT_XML1, 'UTF-8');

        // Deterministic, readable background derived from the name.
        $background = 'hsl('.(crc32($name) % 360).', 55%, 40%)';

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100">'
            .'<rect width="100" height="100" fill="'.$background.'"/>'
            .'<text x="50" y="50" dy=".35em" text-anchor="middle" fill="#ffffff" '
            .'font-family="sans-serif" font-size="42" font-weight="600">'.$initials.'</text>'
            .'</svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
