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
    private const REDACTED = '[REDACTED]';

    private const PII_KEY_PATTERN = '/first_?name|last_?name|person_?name|full_?name|company|email|phone|street|address|city|postal|zip_?code|recipient|contact/i';

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
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::PII_KEY_PATTERN, $key)) {
                $data[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $data[$key] = self::scrubArray($value);
            }
        }

        return $data;
    }
}
