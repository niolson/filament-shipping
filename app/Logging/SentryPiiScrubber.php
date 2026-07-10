<?php

namespace App\Logging;

use Sentry\Breadcrumb;
use Sentry\Event;

/**
 * Defense-in-depth scrubber for Sentry events. Amazon's SP-API data
 * protection policy forbids sending buyer PII to third parties, so any
 * key that looks like a recipient field is redacted from event extras,
 * contexts, request data, and breadcrumb metadata before the event
 * leaves the application.
 *
 * Registered as `before_send` in config/sentry.php (static callable —
 * closures would break `php artisan config:cache`).
 */
class SentryPiiScrubber
{
    public static function scrub(Event $event): Event
    {
        $event->setExtra(self::scrubArray($event->getExtra()));
        $event->setRequest(self::scrubArray($event->getRequest()));

        foreach ($event->getContexts() as $name => $context) {
            $event->setContext($name, self::scrubArray($context));
        }

        $event->setBreadcrumb(array_map(
            fn (Breadcrumb $breadcrumb): Breadcrumb => new Breadcrumb(
                $breadcrumb->getLevel(),
                $breadcrumb->getType(),
                $breadcrumb->getCategory(),
                $breadcrumb->getMessage(),
                self::scrubArray($breadcrumb->getMetadata()),
                $breadcrumb->getTimestamp(),
            ),
            $event->getBreadcrumbs(),
        ));

        return $event;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function scrubArray(array $data): array
    {
        return PiiRedactor::redact($data);
    }
}
