<?php

namespace App\Support;

use Closure;
use enshrined\svgSanitize\Sanitizer;
use Filament\Forms\Components\BaseFileUpload;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Neutralizes stored-XSS in uploaded SVGs.
 *
 * An uploaded SVG is served same-origin from the public disk, and navigating
 * directly to its storage URL renders it as a document — so any embedded
 * `<script>`/event handler executes in the app's origin. We strip that on
 * upload rather than dropping SVG support. See security review issue 08.
 */
class SvgUploadSanitizer
{
    /**
     * A `saveUploadedFileUsing` callback that stores the file normally, then
     * rewrites it with a sanitized copy when it is an SVG.
     */
    public static function saveUsing(): Closure
    {
        return static function (BaseFileUpload $component, TemporaryUploadedFile $file): ?string {
            $path = $component->saveUploadedFile($file);

            if ($path === null || ! self::isSvg($file)) {
                return $path;
            }

            $disk = $component->getDisk();
            $dirty = $disk->get($path);

            if ($dirty === null) {
                return $path;
            }

            $sanitizer = new Sanitizer;
            $sanitizer->removeRemoteReferences(true);
            $clean = $sanitizer->sanitize($dirty);

            if ($clean === false) {
                // Unparseable SVG (malformed/hostile) — fail closed: drop it.
                $disk->delete($path);
                logger()->warning('Rejected an unparseable SVG upload', ['path' => $path]);

                return null;
            }

            $disk->put($path, $clean, $component->getVisibility());

            return $path;
        };
    }

    private static function isSvg(TemporaryUploadedFile $file): bool
    {
        return $file->getMimeType() === 'image/svg+xml'
            || strtolower((string) $file->getClientOriginalExtension()) === 'svg';
    }
}
