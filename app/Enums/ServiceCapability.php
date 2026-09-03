<?php

namespace App\Enums;

enum ServiceCapability
{
    /**
     * The adapter translates this service into carrier-specific API fields.
     */
    case Supported;

    /**
     * The carrier explicitly prohibits this service (policy or legal restriction).
     * Carriers with any Prohibited service will be excluded from rate results,
     * and a reason will be shown to the user.
     */
    case Prohibited;

    /**
     * The offer cannot guarantee this service, whatever the carrier behind it
     * would do. Shopify Shipping picks the carrier and the rate itself, after
     * the purchase, so no promise made before it is one we can keep.
     *
     * Distinct from Prohibited, which is a carrier saying no, and from
     * NotImplemented, which is us not having wired it up. Excluded — visibly —
     * when the shipment hard-requires the service; skipped when it is only a
     * default, since a default is a preference and this offer simply cannot
     * express it. See ADR-0002 decision 8.
     */
    case Unguaranteed;

    /**
     * The carrier may support this service but it hasn't been wired up yet.
     * The request proceeds without the service — no warning shown.
     */
    case NotImplemented;
}
