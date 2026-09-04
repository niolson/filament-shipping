<?php

namespace App\Enums;

/**
 * How well the service recorded on a package is known. See ADR-0003 decision 7.
 *
 * Separate from the requested preference, which is audit metadata about what we
 * asked a postage source for and coexists with any of these outcomes: asking
 * Shopify for Ground Advantage tells us nothing about what it bought.
 *
 * Recorded explicitly rather than inferred from whether `service` is null. A
 * null service under `confirmed` is a bug worth catching, not a synonym for
 * `unknown`, and an inferred service is a non-null value that must never be
 * mistaken for a fact the carrier reported.
 */
enum ServiceEvidence: string
{
    /** The postage source reported the service it sold. */
    case Confirmed = 'confirmed';

    /** Derived by us — from a tracking number, a rate request, a rule. */
    case Inferred = 'inferred';

    /** Nobody can say. Shopify Shipping never reports a purchased service. */
    case Unknown = 'unknown';

    /**
     * Whether this service may be published to a sales channel as fact.
     *
     * A guess sent to a marketplace becomes a buyer-facing fact we cannot
     * retract, and omitting the field costs nothing.
     */
    public function isPublishable(): bool
    {
        return $this === self::Confirmed;
    }
}
