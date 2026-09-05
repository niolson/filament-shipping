<?php

namespace App\Services\PostageSources;

use App\Filament\Pages\UnmappedObservedServices;
use App\Models\Carrier;
use App\Models\CarrierService;
use App\Models\ObservedService;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Normalization — the middle of ADR-0003 decision 2's three concepts.
 *
 * Observation records what a postage source said exists; this decides what we
 * call it. The two stay apart because `carrier_services.carrier_id` is a
 * non-nullable FK: the production `getRates` run returned OnTrac as the
 * cheapest eligible offer and we hold no `Carrier` row for it, so an observed
 * identity that could only exist as a `CarrierService` could not be recorded
 * at all.
 *
 * Nothing here runs off the back of a `getRates` response. Every method is
 * reached from a human pressing a button on {@see UnmappedObservedServices},
 * which is what "promotion creates canonical identities deliberately, or not at
 * all" means in practice. Leaving an identity unmapped forever is a valid
 * terminal state (decision 8), so there is no queue and no backfill.
 *
 * Every write here runs under {@see ObservedService::MAPPING_LOCK}, because the
 * recorder writes the same column from the other direction and the two
 * interleaved leave a row permanently disagreeing with the last decision made.
 */
class ObservedServiceMapper
{
    /** How long a held mapping lock stays valid if the holder dies mid-write. */
    private const LOCK_SECONDS = 10;

    /** How long to wait for another writer to finish before giving up. */
    private const LOCK_WAIT_SECONDS = 5;

    public function __construct(private readonly ServiceApprovalGate $approvals) {}

    /**
     * Alias an observed identity onto a service we already have a row for.
     *
     * @return int observations the mapping now covers
     */
    public function map(ObservedService $observation, CarrierService $carrierService): int
    {
        return $this->locked(fn (): int => $this->applyMapping($observation, $carrierService->getKey()));
    }

    /**
     * Author a `CarrierService` for an identity nothing in the catalog covers,
     * and alias the observation onto it.
     *
     * The `Carrier` is passed in already saved rather than authored here: for
     * OnTrac and the rest, creating it is its own deliberate act, taken through
     * the carrier select's create-option form. This method is the second half
     * of that, not a shortcut past it.
     *
     * @return int observations the mapping now covers
     */
    public function promote(
        ObservedService $observation,
        Carrier $carrier,
        string $serviceCode,
        string $serviceName,
        bool $canShipToPoBoxes = false,
        bool $canShipToMilitaryAddresses = false,
    ): int {
        return $this->locked(fn (): int => DB::transaction(function () use (
            $observation,
            $carrier,
            $serviceCode,
            $serviceName,
            $canShipToPoBoxes,
            $canShipToMilitaryAddresses,
        ): int {
            $carrierService = CarrierService::create([
                'carrier_id' => $carrier->getKey(),
                'service_code' => $serviceCode,
                'name' => $serviceName,
                'active' => true,
                'can_ship_to_po_boxes' => $canShipToPoBoxes,
                'can_ship_to_military_addresses' => $canShipToMilitaryAddresses,
            ]);

            // applyMapping, not map: the lock is already held and is not
            // reentrant, so calling the public method here would deadlock
            // against this very call.
            return $this->applyMapping($observation, $carrierService->getKey());
        }));
    }

    /**
     * Return an identity to the unmapped state, which is a valid place for it
     * to stay.
     *
     * Any approval to spend money on it goes with it. ADR-0003 decision 2 makes
     * normalization a precondition of approval, so an approval that outlived
     * the name it was granted against would authorize automated spending on a
     * service nothing in the catalog describes. Revoking here rather than
     * teaching the gate to check both is what keeps that a stated invariant
     * instead of a property two queries have to agree about — and it is why
     * this runs inside one transaction, and inside the same lock
     * {@see ServiceApprovalGate::grant()} takes.
     *
     * Deliberately not done by {@see map()}: re-aliasing a service onto a
     * different `CarrierService` changes what we call it, not what a purchase
     * buys, and the approval is about the latter.
     *
     * @return array{observations: int, approvals: int} rows returned to unmapped, and approvals withdrawn
     */
    public function unmap(ObservedService $observation): array
    {
        return $this->locked(fn (): array => DB::transaction(function () use ($observation): array {
            // Both writes or neither. Apart, a mapping update that failed
            // after the approvals were already committed would leave the
            // service mapped and silently de-approved — the safe direction,
            // but a state nobody chose and nothing would ever report.
            $approvals = $this->approvals->revokeAll($observation);

            return [
                'observations' => $this->applyMapping($observation, null),
                'approvals' => $approvals,
            ];
        }));
    }

    /**
     * Every row naming the same service, whatever world it was seen in — see
     * {@see ObservedService::scopeSameService()} for why that is the scope a
     * mapping covers.
     *
     * Rows observed *after* this runs are not reached by any update, so
     * {@see ObservedServiceRecorder} reads the same scope when it inserts one.
     * Without that, a service mapped today would come back unmapped the first
     * time it is seen in another environment or marketplace.
     *
     * @return Builder<ObservedService>
     */
    private function sameIdentity(ObservedService $observation): Builder
    {
        return ObservedService::query()->sameService(
            $observation->source,
            $observation->external_carrier_id,
            $observation->external_service_id,
        );
    }

    /**
     * One unconditional write over the whole scope, which is what lets the
     * recorder cooperate with a single lock rather than a merge: whatever the
     * last decision was, every row for the service carries it.
     *
     * @return int observations the decision now covers
     */
    private function applyMapping(ObservedService $observation, ?int $carrierServiceId): int
    {
        return $this->sameIdentity($observation)
            ->update(['carrier_service_id' => $carrierServiceId]);
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     *
     * @throws LockTimeoutException
     */
    private function locked(Closure $callback): mixed
    {
        // Waits rather than refusing: the holder is either another operator's
        // single UPDATE or a recorder insert, both of which are over in
        // milliseconds, and a mapping that silently did not happen is worse
        // than one that took a moment.
        return Cache::lock(ObservedService::MAPPING_LOCK, self::LOCK_SECONDS)
            ->block(self::LOCK_WAIT_SECONDS, $callback);
    }
}
