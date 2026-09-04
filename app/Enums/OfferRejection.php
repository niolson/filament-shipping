<?php

namespace App\Enums;

/**
 * Why an offer could not be spent.
 *
 * Every case fails closed: the operator is sent back to a fresh quote rather
 * than into a purchase whose price, service or entitlement we can no longer
 * vouch for. ADR-0002 decision 4.
 */
enum OfferRejection: string
{
    /** No such offer — purged, or never issued under that identifier. */
    case NotFound = 'not_found';

    /** Real offer, wrong package. A quote is not transferable between parcels. */
    case WrongPackage = 'wrong_package';

    /** The source's window has closed; the price and promise are no longer good. */
    case Expired = 'expired';

    /** Already spent. Buying again would be a second purchase, not a retry. */
    case AlreadyConsumed = 'already_consumed';

    /**
     * Quoted in the other world. Sandbox and production identifiers differ, and
     * so do the hosts they are honoured by, so an offer outlives the toggle
     * only as a record — never as authority.
     */
    case EnvironmentChanged = 'environment_changed';

    public function title(): string
    {
        return match ($this) {
            self::NotFound, self::WrongPackage => 'Rate Unavailable',
            self::Expired => 'Rate Expired',
            self::AlreadyConsumed => 'Rate Already Used',
            self::EnvironmentChanged => 'Sandbox Mode Changed',
        };
    }

    /**
     * Wording aimed at a packer at the Ship page, who needs to know what to do
     * next rather than which invariant held.
     */
    public function message(): string
    {
        return match ($this) {
            self::NotFound => 'This rate is no longer on file. Get rates again and choose one.',
            self::WrongPackage => 'This rate was quoted for a different package. Get rates again for this one.',
            self::Expired => 'This rate has expired. Get rates again to buy at a current price.',
            self::AlreadyConsumed => 'This rate has already been used to buy a label. '
                .'Check the package for a tracking number before buying again — if there is none, get rates again.',
            self::EnvironmentChanged => 'Sandbox mode was switched after this rate was quoted, so it belongs to the '
                .'other environment. Get rates again.',
        };
    }
}
