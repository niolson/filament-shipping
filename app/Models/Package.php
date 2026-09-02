<?php

namespace App\Models;

use App\DataTransferObjects\Shipping\ShipResponse;
use App\Enums\PackageStatus;
use App\Enums\PostageSource;
use App\Enums\SpecialServiceSource;
use App\Enums\TrackingStatus;
use App\Events\PackageCancelled;
use App\Events\PackageShipped;
use App\Services\SpecialServiceResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Laravel\Scout\Attributes\SearchUsingPrefix;
use Laravel\Scout\Searchable;

class Package extends Model
{
    use HasFactory, Searchable;

    protected $fillable = [
        'shipment_id',
        'location_id',
        'carrier_account_id',
        'postage_data_source_id',
        'postage_source',
        'box_size_id',
        'tracking_number',
        'carrier',
        'service',
        'metadata',
        'carrier_request_payload',
        'label_data',
        'label_orientation',
        'label_format',
        'label_dpi',
        'label_printed_at',
        'weight',
        'height',
        'width',
        'length',
        'cost',
        'weight_mismatch',
        'status',
        'shipped_at',
        'ship_date',
        'shipped_by_user_id',
        'exported',
        'manifest_id',
        'tracking_status',
        'tracking_updated_at',
        'delivered_at',
        'tracking_details',
        'tracking_checked_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'carrier_request_payload' => 'array',
        'weight' => 'decimal:2',
        'height' => 'decimal:2',
        'width' => 'decimal:2',
        'length' => 'decimal:2',
        'cost' => 'decimal:2',
        'weight_mismatch' => 'boolean',
        'label_printed_at' => 'datetime',
        'status' => PackageStatus::class,
        'postage_source' => PostageSource::class,
        'shipped_at' => 'datetime',
        'ship_date' => 'date',
        'exported' => 'boolean',
        'tracking_status' => TrackingStatus::class,
        'tracking_updated_at' => 'datetime',
        'delivered_at' => 'datetime',
        'tracking_details' => 'array',
        'tracking_checked_at' => 'datetime',
    ];

    /**
     * @return array<string, mixed>
     */
    #[SearchUsingPrefix(['tracking_number'])]
    public function toSearchableArray(): array
    {
        return [
            'tracking_number' => $this->tracking_number,
        ];
    }

    /**
     * @return HasMany<PackageItem, $this>
     */
    public function packageItems(): HasMany
    {
        return $this->hasMany(PackageItem::class);
    }

    /**
     * @return HasMany<PackageExport, $this>
     */
    public function packageExports(): HasMany
    {
        return $this->hasMany(PackageExport::class);
    }

    /**
     * @return BelongsTo<Shipment, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<CarrierAccount, $this>
     */
    public function carrierAccount(): BelongsTo
    {
        return $this->belongsTo(CarrierAccount::class);
    }

    /**
     * The data source the postage was bought through, when it was not bought
     * on a carrier account of ours. Not the shipment's import source — see
     * ADR-0002.
     *
     * @return BelongsTo<DataSource, $this>
     */
    public function postageDataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'postage_data_source_id');
    }

    /**
     * @return BelongsTo<BoxSize, $this>
     */
    public function boxSize(): BelongsTo
    {
        return $this->belongsTo(BoxSize::class);
    }

    /**
     * @return BelongsTo<Manifest, $this>
     */
    public function manifest(): BelongsTo
    {
        return $this->belongsTo(Manifest::class);
    }

    /**
     * @return HasMany<RateQuote, $this>
     */
    public function rateQuotes(): HasMany
    {
        return $this->hasMany(RateQuote::class);
    }

    /**
     * @return HasMany<PackageSpecialService, $this>
     */
    public function specialServices(): HasMany
    {
        return $this->hasMany(PackageSpecialService::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function shippedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shipped_by_user_id');
    }

    /**
     * Packages whose label can still be sent to a printer.
     *
     * Voiding a label after the fact clears both the label data and the printed
     * timestamp, so without this a voided package reads as "shipped but never
     * printed" forever.
     *
     * @param  Builder<Package>  $query
     * @return Builder<Package>
     */
    public function scopePrintable(Builder $query): Builder
    {
        return $query
            ->where('status', PackageStatus::Shipped)
            ->whereNotNull('label_data');
    }

    /**
     * Whether this package's postage was bought through Shopify Shipping.
     *
     * Shopify labels are billed to the merchant's Shopify account and can only
     * be voided or refunded in the Shopify admin, so several parts of the UI
     * have to treat them differently from a label bought on our own carrier
     * account.
     */
    public function isShopifyShipped(): bool
    {
        return $this->postage_source === PostageSource::PostageDataSource;
    }

    /**
     * Compute whether there's a weight mismatch (>10% discrepancy)
     * between the actual package weight and the expected weight
     * based on the packed products.
     */
    public function computeWeightMismatch(): bool
    {
        $this->loadMissing('packageItems.product');

        $expectedWeight = (float) $this->packageItems->sum(
            fn ($item) => ($item->product?->weight ?? 0) * $item->quantity
        );

        $actualWeight = (float) $this->weight;

        if ($actualWeight <= 0 || $expectedWeight <= 0) {
            return false;
        }

        return abs($actualWeight - $expectedWeight) / max($actualWeight, 0.01) > 0.10;
    }

    /**
     * Mark this package as shipped with the given response data.
     *
     * Every new transition to Shipped has to say where its postage was bought,
     * so the caller passes the discriminator rather than letting it be inferred
     * from whichever pointer happens to be set. See ADR-0002.
     *
     * @throws \InvalidArgumentException If the postage source and the response's pointers disagree
     * @throws \RuntimeException If the package state changed (optimistic locking)
     */
    public function markShipped(ShipResponse $response, PostageSource $postageSource, ?int $shippedByUserId = null): void
    {
        // Before the transaction, so a rejected provenance writes nothing at all.
        $this->assertProvenanceIsConsistent($postageSource, $response);

        DB::transaction(function () use ($response, $postageSource, $shippedByUserId): void {
            // Carriers that record facts of their own (Shopify reports which
            // carrier it picked, and its own label ID) merge into whatever the
            // package already carries rather than replacing it.
            //
            // Merged onto the stored row, not this instance: shipping can write
            // metadata mid-flight — Shopify records an in-flight purchase so it
            // can be resumed — and merging a copy loaded before that would
            // resurrect keys the carrier had deliberately cleared.
            $metadata = [];

            if ($response->metadata !== []) {
                $stored = json_decode(
                    (string) DB::table('packages')->where('id', $this->id)->value('metadata'),
                    true,
                ) ?: [];

                $metadata = ['metadata' => json_encode(array_merge($stored, $response->metadata))];
            }

            // Optimistic locking - ensure package hasn't been shipped already
            $updated = DB::table('packages')
                ->where('id', $this->id)
                ->where('status', PackageStatus::Unshipped->value)
                ->update($metadata + [
                    'tracking_number' => $response->trackingNumber,
                    'carrier_account_id' => $response->carrierAccountId,
                    'postage_data_source_id' => $response->postageDataSourceId,
                    'postage_source' => $postageSource->value,
                    'cost' => $response->cost,
                    'carrier' => $response->carrier,
                    'service' => $response->service,
                    'label_data' => $response->labelData,
                    'label_orientation' => $response->labelOrientation ?? 'portrait',
                    'label_format' => $response->labelFormat ?? 'pdf',
                    'label_dpi' => $response->labelDpi,
                    'label_printed_at' => null,
                    'status' => PackageStatus::Shipped->value,
                    'shipped_at' => now(),
                    'ship_date' => $response->shipDate?->format('Y-m-d'),
                    'shipped_by_user_id' => $shippedByUserId,
                    'tracking_status' => TrackingStatus::PreTransit->value,
                    'tracking_updated_at' => null,
                    'delivered_at' => null,
                    'tracking_details' => null,
                    'tracking_checked_at' => null,
                    'exported' => false,
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                throw new \RuntimeException('Package has already been shipped or was modified by another process.');
            }

            // Refresh the model to get the updated state
            $this->refresh();
        });

        $this->recordAppliedSpecialServices($response->appliedServices);

        $this->load('shipment.shipmentItems');
        $this->shipment->updateShippedStatus();

        PackageShipped::dispatch($this, $this->shipment);
    }

    /**
     * Reject a ship that would record provenance disagreeing with its pointers.
     *
     * Enforced here rather than in a model observer or a saving hook: markShipped()
     * writes through the query builder for optimistic locking, so model events
     * never fire for it.
     *
     * A `carrier_account` purchase may legitimately name no account — the fake
     * adapters ship without one, and a real adapter records `$account?->id` — so
     * only the foreign pointer is forbidden there. Sales-channel postage is
     * different: a source we cannot name is a source we cannot void, track or
     * manifest against, so its pointer is required.
     *
     * @throws \InvalidArgumentException
     */
    private function assertProvenanceIsConsistent(PostageSource $postageSource, ShipResponse $response): void
    {
        if ($postageSource === PostageSource::CarrierAccount && $response->postageDataSourceId !== null) {
            throw new \InvalidArgumentException(
                'A carrier_account purchase cannot also point at a postage data source.'
            );
        }

        if ($postageSource === PostageSource::PostageDataSource) {
            if ($response->postageDataSourceId === null) {
                throw new \InvalidArgumentException(
                    'A postage_data_source purchase must name the data source the postage was bought through.'
                );
            }

            if ($response->carrierAccountId !== null) {
                throw new \InvalidArgumentException(
                    'A postage_data_source purchase cannot also point at a carrier account.'
                );
            }
        }
    }

    /**
     * Record which special services were actually applied when this package was shipped.
     *
     * @param  array<string>  $appliedServiceCodes  Service codes confirmed sent to the carrier
     */
    private function recordAppliedSpecialServices(array $appliedServiceCodes): void
    {
        if (empty($appliedServiceCodes)) {
            return;
        }

        $shippingMethod = $this->shipment?->shippingMethod;
        $services = SpecialService::whereIn('code', $appliedServiceCodes)->get()->keyBy('code');
        $productRequiredCodes = app(SpecialServiceResolver::class)->resolveProductRequiredCodes($this);
        $now = now();

        foreach ($appliedServiceCodes as $code) {
            $service = $services->get($code);

            if (! $service) {
                continue;
            }

            if ($productRequiredCodes->has($code)) {
                $source = SpecialServiceSource::Product;
                $sourceReference = (string) $productRequiredCodes->get($code);
            } else {
                $pivotMode = $shippingMethod?->specialServices()
                    ->where('code', $code)
                    ->value('shipping_method_special_service.mode');

                $source = $pivotMode ? SpecialServiceSource::ShippingMethod : SpecialServiceSource::System;
                $sourceReference = $pivotMode ? (string) $shippingMethod->id : null;
            }

            $config = null;

            if ($code === 'declared_value') {
                $amount = app(SpecialServiceResolver::class)->declaredValueForPackage($this);
                $config = $amount !== null ? ['amount' => $amount, 'currency' => 'USD'] : null;
            }

            $this->specialServices()->updateOrCreate(
                ['special_service_id' => $service->id],
                [
                    'source' => $source,
                    'source_reference' => $sourceReference,
                    'config' => $config,
                    'applied_at' => $now,
                ],
            );
        }
    }

    /**
     * Clear shipping data from this package (void label).
     *
     * @throws \RuntimeException If the package state changed (optimistic locking)
     */
    public function clearShipping(): void
    {
        DB::transaction(function (): void {
            // Optimistic locking - ensure package is still shipped
            $updated = DB::table('packages')
                ->where('id', $this->id)
                ->where('status', PackageStatus::Shipped->value)
                ->update([
                    'tracking_number' => null,
                    'carrier_account_id' => null,
                    'postage_data_source_id' => null,
                    'postage_source' => null,
                    'carrier' => null,
                    'service' => null,
                    'cost' => null,
                    'label_data' => null,
                    'label_orientation' => null,
                    'label_format' => 'pdf',
                    'label_dpi' => null,
                    'label_printed_at' => null,
                    'status' => PackageStatus::Unshipped->value,
                    'shipped_at' => null,
                    'shipped_by_user_id' => null,
                    'tracking_status' => null,
                    'tracking_updated_at' => null,
                    'delivered_at' => null,
                    'tracking_details' => null,
                    'tracking_checked_at' => null,
                    'exported' => false,
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                throw new \RuntimeException('Package shipping state has changed. It may have already been voided.');
            }

            PackageExport::query()->where('package_id', $this->id)->delete();

            // Refresh the model to get the updated state
            $this->refresh();
        });

        $this->load('shipment.shipmentItems');
        $this->shipment->updateShippedStatus();

        PackageCancelled::dispatch($this, $this->shipment);
    }
}
