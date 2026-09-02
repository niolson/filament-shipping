<?php

namespace App\Enums;

/**
 * Where a package's postage was bought — the axis `packages.carrier` used to
 * conflate with the carrier of record. See ADR-0002.
 *
 * Recorded explicitly rather than inferred from which pointer column is null. A
 * direct purchase may legitimately name no carrier account, so absence is not a
 * reliable signal, and `postage_source` being null has its own meaning: the
 * package has not been shipped yet.
 */
enum PostageSource: string
{
    /** Bought directly from the carrier, on one of our own carrier accounts. */
    case CarrierAccount = 'carrier_account';

    /** Bought through sales-channel postage — Shopify Shipping, Amazon Buy Shipping. */
    case PostageDataSource = 'postage_data_source';
}
