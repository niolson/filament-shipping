<?php

namespace App\Services\PostageSources;

use App\DataTransferObjects\PostageSources\PostageSourceCandidate;
use App\DataTransferObjects\PostageSources\PostageSourceResolution;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\DataSource;
use App\Models\Package;
use App\Services\Carriers\CarrierRegistry;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Illuminate\Support\Collection;

/**
 * Decides which postage source is asked for a package, before anything is asked.
 *
 * ADR-0002 decision 9. Once more than one source can supply a shipment, "follow
 * the `CarrierAccount` pattern" stops being enough: that pattern binds an offer
 * to a *carrier*, and what a purchase needs is the specific source *instance*
 * that will sell it.
 *
 * The two arms answer genuinely different questions, which is why they do not
 * share a mechanism:
 *
 * - **Channel postage binds to the shipment's originating data source and
 *   nothing else.** A Shopify or Amazon Buy Shipping purchase is keyed to a
 *   fulfillment order that exists in exactly one account, so scoping does not
 *   enter into it: either the shipment came from that account or the source is
 *   not a candidate at all. There is no precedence chain here to get wrong and
 *   no fallback — a shipment that came from nowhere resolves to nothing.
 * - **Carrier accounts resolve by scope**, on the existing `(location, client)`
 *   precedence, which is also where Amazon Shipping on a non-Amazon order will
 *   land: it sells against an account of ours rather than against somebody
 *   else's order.
 *
 * This resolves *who may sell*, at quote time. What a shipped package's postage
 * actually was is recorded provenance, read by `PostageSourceDispatcher` from
 * the `postage_source` discriminator — never re-derived from here, since a
 * shipment can be re-pointed at another source after its label was bought.
 */
class PostageSourceResolver
{
    /**
     * Import drivers whose account can also sell postage.
     *
     * Amazon joins the list with its Buy Shipping adapter. A driver's presence
     * here says the *kind* of account can sell postage, not that this one is
     * entitled to — Amazon's per-marketplace approval is a separate gate that
     * belongs with that adapter.
     *
     * @var array<int, class-string>
     */
    private const CHANNEL_POSTAGE_DRIVERS = [ShopifySource::class];

    public function __construct(
        private readonly CarrierRegistry $carrierRegistry,
    ) {}

    /**
     * The channel postage source bound to this package, or null.
     *
     * Null covers every way a package can fail to have one, deliberately
     * without distinguishing them: no shipment, an import that came from a
     * database query, a source deactivated since, a driver that sells no
     * postage. None of them is a reason to look at a second account — reading
     * one shop's fulfillment orders with another shop's credentials is the
     * failure this rule exists to make impossible.
     */
    public function channelSourceFor(Package $package): ?DataSource
    {
        $package->loadMissing('shipment.dataSource');

        $source = $package->shipment?->dataSource;

        if (! $source || ! $source->active) {
            return null;
        }

        return in_array($source->source_type, self::CHANNEL_POSTAGE_DRIVERS, true)
            ? $source
            : null;
    }

    /**
     * Every source eligible to sell this package a label: the bound channel
     * source, then one carrier account per named carrier.
     *
     * Carriers are named by the caller because it already knows which ones it
     * is about to quote — the shipping method's services, or every configured
     * adapter when there is no method. Resolving is not free (a query and a
     * precedence walk per carrier), and resolving carriers nobody asked about
     * would spend it for nothing.
     *
     * One source per carrier comes out of this, unless the winning scope opted
     * into rate shopping. It is not enforced here on top of the scope walk,
     * because it cannot be violated there: `carrier_account_scopes` is unique
     * on `(carrier_id, location_key, client_key)`, so each of the four
     * precedence bands holds at most one scope and the walk has nothing to
     * arbitrate. That constraint, not a check in this class, is what makes
     * "never an arbitrary pick" true for direct carriers.
     *
     * @param  array<int, string>  $carrierNames
     */
    public function resolve(Package $package, array $carrierNames = []): PostageSourceResolution
    {
        /** @var Collection<int, PostageSourceCandidate> $candidates */
        $candidates = new Collection;
        $conflicts = [];

        if ($channel = $this->channelSourceFor($package)) {
            $candidates->push(PostageSourceCandidate::fromDataSource($channel));
        }

        $package->loadMissing('shipment');
        $locationId = $package->location_id;
        $clientId = $package->shipment?->client_id;

        $names = array_values(array_unique($carrierNames));
        $carrierIds = Carrier::whereIn('name', $names)->pluck('id', 'name');

        foreach ($names as $carrierName) {
            $carrierId = $carrierIds->get($carrierName);

            if ($carrierId === null) {
                continue;
            }

            $accounts = CarrierAccount::resolveForShipment($carrierId, $locationId, $clientId);

            if ($accounts->isEmpty()) {
                continue;
            }

            // A resale channel holds a `Carrier` row so its offers have services
            // to hang off, and that row is what makes this reachable: an account
            // scoped to it claims we buy postage from a storefront the way we
            // buy it from USPS. We do not — its postage comes from the data
            // source above, on the merchant's own account — so two sources would
            // now claim one carrier and neither may be picked over the other.
            if (! $this->carrierRegistry->policyFor($carrierName)) {
                $conflicts[] = [
                    'carrier' => $carrierName,
                    'reason' => "{$carrierName} is a postage source, not a carrier we hold an account with, so the carrier account scoped to it cannot be used to buy a label. Remove it in Carrier Accounts; postage bought through {$carrierName} resolves to the data source the shipment came from.",
                ];

                continue;
            }

            foreach ($accounts as $account) {
                $candidates->push(PostageSourceCandidate::fromCarrierAccount($account, $carrierName));
            }
        }

        return new PostageSourceResolution($candidates, $conflicts);
    }
}
