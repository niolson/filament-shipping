<?php

namespace App\Enums;

/**
 * Where a package's postage was bought — the axis `packages.carrier` used to
 * conflate with the carrier of record. See ADR-0002.
 *
 * Recorded explicitly rather than inferred from which pointer column is null:
 * two nullable pointers cannot tell a deliberately recorded legacy package
 * apart from missing or corrupt data.
 */
enum PostageSource: string
{
    /** Bought directly from the carrier, on one of our own carrier accounts. */
    case CarrierAccount = 'carrier_account';

    /** Bought through sales-channel postage — Shopify Shipping, Amazon Buy Shipping. */
    case PostageDataSource = 'postage_data_source';

    /**
     * Shipped before provenance was recorded; genuinely unrecoverable.
     *
     * Written only by the backfill, never by a new purchase.
     */
    case LegacyUnknown = 'legacy_unknown';
}
