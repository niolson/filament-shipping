<?php

namespace App\DataTransferObjects\PostageSources;

use App\Enums\OfferRejection;
use App\Models\ShippingOffer;

/**
 * The result of trying to spend an offer.
 *
 * A result object rather than a nullable model because a refusal has to say
 * *which* refusal: an expired offer sends the operator back for a fresh quote,
 * while an offer already spent means asking whether a label exists before
 * anyone buys a second one. Collapsing both to null loses the difference at
 * exactly the moment it costs money.
 */
readonly class OfferRedemption
{
    private function __construct(
        public ?ShippingOffer $offer,
        public ?OfferRejection $rejection,
    ) {}

    /**
     * The offer stands: either just claimed by `OfferStore::redeem()`, or found
     * spendable by `OfferStore::inspect()`. Which of the two is the caller's
     * own context, so one word covers both.
     */
    public static function available(ShippingOffer $offer): self
    {
        return new self($offer, null);
    }

    /**
     * @param  ShippingOffer|null  $offer  The offer as it now stands, when one was found — a rejected redemption still tells the caller what it was rejecting.
     */
    public static function rejected(OfferRejection $rejection, ?ShippingOffer $offer = null): self
    {
        return new self($offer, $rejection);
    }

    public function wasRejected(): bool
    {
        return $this->rejection !== null;
    }

    public function title(): string
    {
        return $this->rejection?->title() ?? 'Rate Available';
    }

    public function message(): string
    {
        return $this->rejection?->message() ?? '';
    }

    /**
     * Whether the caller should recover a purchase before considering a retry.
     *
     * True only for an offer spent with nothing confirmed back — the state
     * where a label may exist upstream that we never recorded.
     */
    /**
     * Whether a fresh quote is the whole remedy — see
     * {@see OfferRejection::requiresRequote()}.
     */
    public function requiresRequote(): bool
    {
        return $this->rejection?->requiresRequote() ?? false;
    }

    public function requiresPurchaseRecovery(): bool
    {
        return $this->rejection === OfferRejection::AlreadyConsumed
            && $this->offer?->isAwaitingPurchaseConfirmation() === true;
    }
}
