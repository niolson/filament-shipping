<?php

namespace App\Services\PostageSources;

use App\Enums\SourceEnvironment;
use App\Exceptions\UnnormalizedServiceApprovalException;
use App\Models\Client;
use App\Models\ObservedService;
use App\Models\ServiceApproval;
use App\Models\ShippingOffer;
use App\Models\User;
use App\Services\RateSelector;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Whether automation may spend money on a discovered service — ADR-0003
 * decisions 3 and 4.
 *
 * The last of the three concepts decision 2 keeps apart, and the only one that
 * is about money rather than about facts or names. {@see ObservedServiceRecorder}
 * writes what a source reported; {@see ObservedServiceMapper} says what we call
 * it; this says whether an unattended path may buy it.
 *
 * **Deny by default, and deny is the whole safety mechanism.** An unapproved
 * service is not hidden and not broken: a packer sees it on the Ship page with
 * its price and picks it, taking responsibility. What it may not do is win
 * `RateSelector::selectBest()` at 03:00 on an account nobody approved it for.
 * That split — on *who is choosing* — is what makes discovery acceptable at
 * all, so every answer this class gives to an unattended caller starts from no.
 *
 * Three axes of scope, all load-bearing:
 *
 * - **postage source**, because a service offered through Amazon and the same
 *   service bought directly are two different purchases;
 * - **client**, because the approval is consent from whoever is billed;
 * - **environment**, because Amazon's sandbox returned only Amazon Shipping
 *   where production for the same channel returned OnTrac, UPS and USPS and no
 *   Amazon Shipping at all. An approval earned in sandbox is evidence about
 *   nothing that costs money.
 *
 * Nothing here is cached. `CacheService` holds carrier services for an hour,
 * which is right for authored configuration and would be wrong here: revoking
 * an approval has to stop the next selection, not the next hour's.
 */
class ServiceApprovalGate
{
    /** How long a held mapping lock stays valid if the holder dies mid-write. */
    private const LOCK_SECONDS = 10;

    /** How long to wait for another writer to finish before giving up. */
    private const LOCK_WAIT_SECONDS = 5;

    /**
     * May an unattended path buy this service for this client?
     *
     * The environment is a required argument rather than a defaulted one on
     * purpose. `SourceEnvironment::current()` is nearly always the right value
     * to pass, and a parameter that fills itself in is exactly how a sandbox
     * approval would come to authorize a production purchase — the one thing
     * decision 3 exists to prevent. Callers holding a {@see ShippingOffer}
     * should pass the environment stamped on it, which is the world the quote
     * actually came from.
     *
     * A null client is denied rather than treated as "any". Every package
     * reaches a client through its shipment, so a missing one is a caller that
     * has lost track of whose money this is.
     */
    public function approved(
        string $source,
        SourceEnvironment $environment,
        string $externalCarrierId,
        string $externalServiceId,
        ?int $clientId,
    ): bool {
        if ($clientId === null) {
            return false;
        }

        return ServiceApproval::query()
            ->forService($source, $environment, $externalCarrierId, $externalServiceId)
            ->where('client_id', $clientId)
            ->exists();
    }

    /**
     * Everything this client has approved from one source in one world, keyed
     * the way {@see ObservedService::serviceKey()} keys a service.
     *
     * For filtering a whole rate list against one query rather than one per
     * offer: an Amazon `getRates` can return several eligible offers, and
     * {@see RateSelector} is on the Ship page's hot path.
     *
     * @return Collection<int, string>
     */
    public function approvedServiceKeys(string $source, SourceEnvironment $environment, ?int $clientId): Collection
    {
        if ($clientId === null) {
            return collect();
        }

        return ServiceApproval::query()
            ->where('source', $source)
            ->where('environment', $environment)
            ->where('client_id', $clientId)
            ->get(['source', 'external_carrier_id', 'external_service_id'])
            ->map(fn (ServiceApproval $approval): string => ObservedService::serviceKey(
                $approval->source,
                $approval->external_carrier_id,
                $approval->external_service_id,
            ))
            ->values();
    }

    /**
     * Approve the service an observation names, for one client, in the world
     * that observation was made in.
     *
     * Normalization first, always: ADR-0003 decision 2 puts promotion before
     * approval rather than beside it, and an approval for something nobody has
     * named would authorize spending on a service no report could describe.
     *
     * Taken under {@see ObservedService::MAPPING_LOCK} because the check and
     * the write straddle a column another operator may be clearing from the
     * mapping page. Without it, an approval granted in the window between
     * reading `carrier_service_id` and inserting the row outlives the mapping
     * that justified it — approved and unnamed, which is the one combination
     * this class refuses to produce.
     *
     * The approver is required rather than nullable. An approval is a standing
     * permission to spend somebody's money unattended, and one that cannot say
     * on whose authority it was granted is the row this class exists not to
     * write — a nullable parameter with a convenient default is how that row
     * gets created by accident. Callers that are not a signed-in operator have
     * to name the user they are acting for.
     *
     * @throws UnnormalizedServiceApprovalException
     */
    public function grant(ObservedService $observation, Client $client, User $approver): ServiceApproval
    {
        return Cache::lock(ObservedService::MAPPING_LOCK, self::LOCK_SECONDS)->block(
            self::LOCK_WAIT_SECONDS,
            fn (): ServiceApproval => $this->grantUnderLock($observation, $client, $approver),
        );
    }

    /**
     * Withdraw one client's approval.
     *
     * Unlocked, unlike {@see grant()}: revocation only ever moves towards the
     * safe answer, so racing a mapping change cannot produce a state worth
     * protecting against.
     *
     * @return int approvals withdrawn — 0 when there was nothing to withdraw
     */
    public function revoke(ObservedService $observation, Client $client): int
    {
        return $this->withdraw(
            $this->scopeFor($observation)->where('client_id', $client->getKey())
        );
    }

    /**
     * Withdraw every client's approval of this service, in every world.
     *
     * Called by {@see ObservedServiceMapper::unmap()}, which is why this does
     * not take {@see ObservedService::MAPPING_LOCK} itself — the mapper is
     * already holding it and the lock is not reentrant. Unmapping revokes
     * rather than merely suspending: normalization is the precondition of
     * approval, so withdrawing the name withdraws the permission, visibly and
     * with a count, instead of leaving a row that silently means nothing.
     *
     * Environment-blind, and this is the one place that is right. A mapping
     * covers every world the service was seen in
     * ({@see ObservedService::scopeSameService()}), so unmapping a production
     * row also unmaps the sandbox one — and a revocation narrower than the
     * unmapping that triggered it would leave precisely the state this class
     * refuses to produce: approved, and named nothing.
     *
     * @return int approvals withdrawn
     */
    public function revokeAll(ObservedService $observation): int
    {
        return $this->withdraw(
            ServiceApproval::query()
                ->where('source', $observation->source)
                ->where('external_carrier_id', $observation->external_carrier_id)
                ->where('external_service_id', $observation->external_service_id)
        );
    }

    /**
     * Set the exact list of clients approved for this service, granting and
     * revoking as needed.
     *
     * What the approval form submits. One lock and one transaction over both
     * halves, so a half-applied change cannot leave a client approved because
     * the revoke that was meant to follow failed.
     *
     * @param  list<int>  $clientIds
     * @return array{granted: int, revoked: int}
     *
     * @throws UnnormalizedServiceApprovalException
     */
    public function syncClients(ObservedService $observation, array $clientIds, User $approver): array
    {
        return Cache::lock(ObservedService::MAPPING_LOCK, self::LOCK_SECONDS)->block(
            self::LOCK_WAIT_SECONDS,
            function () use ($observation, $clientIds, $approver): array {
                $wanted = collect($clientIds)->map(fn (int|string $id): int => (int) $id)->unique();

                return DB::transaction(function () use ($observation, $wanted, $approver): array {
                    $existing = $this->approvedClientIds($observation);

                    $revoked = $existing->diff($wanted)->isEmpty()
                        ? 0
                        : $this->withdraw(
                            $this->scopeFor($observation)
                                ->whereIn('client_id', $existing->diff($wanted)->all())
                        );

                    $granted = $wanted->diff($existing);

                    foreach ($granted as $clientId) {
                        $this->grantUnderLock($observation, Client::findOrFail($clientId), $approver);
                    }

                    return ['granted' => $granted->count(), 'revoked' => $revoked];
                });
            },
        );
    }

    /**
     * The clients that have approved this service in the world it was observed
     * in.
     *
     * @return Collection<int, int>
     */
    public function approvedClientIds(ObservedService $observation): Collection
    {
        return $this->scopeFor($observation)
            ->pluck('client_id')
            ->map(fn (int|string $id): int => (int) $id)
            ->values();
    }

    /**
     * Delete approvals one hydrated model at a time.
     *
     * Not `->delete()` on the query. A mass delete never loads a model and so
     * never fires `deleted`, which is what `AuditableObserver` listens for —
     * the audit log would have carried every grant of permission to spend money
     * and no withdrawal of one, which is the half that gets asked about after
     * the fact.
     *
     * The row count is bounded by clients × environments for a single service,
     * so paying a delete per row buys the audit trail cheaply. Callers that
     * need the two writes to commit together wrap this in their own
     * transaction — {@see ObservedServiceMapper::unmap()} does.
     *
     * @param  Builder<ServiceApproval>  $query
     * @return int approvals withdrawn
     */
    private function withdraw(Builder $query): int
    {
        $approvals = $query->get();

        foreach ($approvals as $approval) {
            $approval->delete();
        }

        return $approvals->count();
    }

    /**
     * The write half of {@see grant()}, with the lock assumed held.
     */
    private function grantUnderLock(ObservedService $observation, Client $client, User $approver): ServiceApproval
    {
        if (! $observation->fresh()?->isMapped()) {
            throw new UnnormalizedServiceApprovalException(
                "Cannot approve {$observation->displayName()}: map it to a carrier service first."
            );
        }

        return ServiceApproval::updateOrCreate(
            [
                'source' => $observation->source,
                'environment' => $observation->environment,
                'external_carrier_id' => $observation->external_carrier_id,
                'external_service_id' => $observation->external_service_id,
                'client_id' => $client->getKey(),
            ],
            [
                'approved_by_user_id' => $approver->getKey(),
                // Snapshotted, not read back through the relation: the point of
                // recording who authorized a spend is that the answer survives
                // both the audit log's retention and the account being deleted.
                'approved_by_name' => $approver->name,
                'approved_at' => now(),
            ],
        );
    }

    /**
     * Every approval covering the service this observation names, in its world.
     *
     * Narrower than the scope a mapping covers — see
     * {@see ServiceApproval::scopeForService()} — by exactly one axis, the
     * environment.
     *
     * @return Builder<ServiceApproval>
     */
    private function scopeFor(ObservedService $observation): Builder
    {
        return ServiceApproval::query()->forService(
            $observation->source,
            $observation->environment,
            $observation->external_carrier_id,
            $observation->external_service_id,
        );
    }
}
