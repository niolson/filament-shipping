<?php

namespace App\Services;

use App\DataTransferObjects\Tracking\TrackShipmentResponse;
use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Enums\TrackingStatus;
use App\Events\TrackingStatusUpdated;
use App\Exceptions\Carriers\CarrierUnavailableException;
use App\Models\Package;
use App\Models\User;
use App\Notifications\TrackingExceptionDetected;
use App\Services\PostageSources\PostageSourceDispatcher;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

class TrackingService
{
    public function __construct(
        private readonly PostageSourceDispatcher $dispatcher,
    ) {}

    /**
     * Ask whoever bought this label where the parcel is.
     *
     * The question goes to the postage source, not the carrier: after USPS
     * changed tracking entitlement in April 2026, the right to a parcel's
     * tracking data follows the account that bought the postage. See ADR-0002.
     */
    public function refreshPackage(Package $package): TrackShipmentResponse
    {
        if ($package->status !== PackageStatus::Shipped || ! $package->tracking_number || ! $package->carrier) {
            return TrackShipmentResponse::failure('Package is not eligible for tracking.');
        }

        try {
            $response = $this->dispatcher->trackShipment($package);
        } catch (CarrierUnavailableException $e) {
            // A configuration state (e.g. sandbox mode with an OAuth account), not a
            // failure worth a stack trace — record it on the package and move on.
            Log::warning('Tracking skipped: carrier unavailable', [
                'package_id' => $package->id,
                'carrier' => $e->carrier,
                'reason' => $e->getMessage(),
            ]);

            $response = TrackShipmentResponse::failure($e->getMessage());
        } catch (Throwable $e) {
            // A tracking check is a status read, and a failed read is a fact to
            // record rather than an error to propagate. Sources reach the
            // network here — a throttled Shopify reply, a dropped connection —
            // and letting that escape would 500 the Filament action a packer
            // clicked and fail the queued refresh job instead of writing down
            // that the check did not land.
            Log::error('Tracking check failed', [
                'package_id' => $package->id,
                'carrier' => $package->carrier,
                'exception' => $e::class,
                'reason' => $e->getMessage(),
            ]);

            $response = TrackShipmentResponse::failure('Could not reach the source that sold this label.');
        }

        $this->record($package, $response);

        return $response;
    }

    /**
     * Write a tracking answer onto the package.
     *
     * Public because a caller that already holds the answer should not make the
     * request again to store it: the Shopify fulfillment poll reads the void
     * state and the delivery state out of one response, and records the second
     * here.
     */
    public function record(Package $package, TrackShipmentResponse $response): void
    {
        $previousStatus = $package->tracking_status;
        $details = $package->tracking_details ?? [];

        $details['message'] = $response->message;
        $details['supported'] = $response->supported;
        $details['status_label'] = $response->statusLabel;
        $details['estimated_delivery_at'] = $response->estimatedDeliveryAt?->toIso8601String();
        $details['events'] = $response->eventsToArray();
        $details['raw'] = $response->details['raw'] ?? ($details['raw'] ?? null);

        $package->forceFill([
            'tracking_checked_at' => now(),
            'tracking_updated_at' => $response->success ? now() : ($package->tracking_updated_at ?? now()),
            'tracking_details' => $details,
        ]);

        if ($response->success && $response->status) {
            $package->tracking_status = $response->status;

            // Only ever set a delivery date; never clear an existing one. A later
            // poll can resolve a null date (e.g. a delivered event without a
            // parseable timestamp) and must not wipe a date recorded earlier.
            if ($response->status === TrackingStatus::Delivered && $response->deliveredAt !== null) {
                $package->delivered_at = $response->deliveredAt->toMutable();
            }
        }

        $package->save();

        if ($response->success && $response->status && $previousStatus !== $response->status) {
            TrackingStatusUpdated::dispatch($package->fresh(), $previousStatus, $response->status);
        }

        $this->notifyIfNeeded($package->fresh(), $previousStatus, $response);
    }

    private function notifyIfNeeded(Package $package, ?TrackingStatus $previousStatus, TrackShipmentResponse $response): void
    {
        if (! $response->success || ! $response->status) {
            return;
        }

        if ($response->status === TrackingStatus::Exception && $previousStatus !== TrackingStatus::Exception) {
            $this->notifyOperations($package, 'Carrier reported an exception for this package.');
            $this->markAlertSent($package, 'exception_notified_at');
        }

        if (
            $response->status === TrackingStatus::PreTransit
            && $package->shipped_at instanceof CarbonInterface
            && $package->shipped_at->lte(now()->subHours(48))
            && ! data_get($package->tracking_details, 'alerts.pre_transit_48h_notified_at')
        ) {
            $this->notifyOperations($package, 'Package has remained in pre-transit for more than 48 hours.');
            $this->markAlertSent($package, 'pre_transit_48h_notified_at');
        }
    }

    private function notifyOperations(Package $package, string $reason): void
    {
        User::query()
            ->where('active', true)
            ->whereIn('role', [Role::Manager->value, Role::Admin->value])
            ->get()
            ->each(fn (User $user) => $user->notify(new TrackingExceptionDetected($package, $reason)));
    }

    private function markAlertSent(Package $package, string $key): void
    {
        $details = $package->tracking_details ?? [];
        data_set($details, "alerts.{$key}", now()->toIso8601String());
        $package->forceFill(['tracking_details' => $details])->save();
    }
}
