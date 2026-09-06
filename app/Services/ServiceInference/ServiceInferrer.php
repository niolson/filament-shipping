<?php

namespace App\Services\ServiceInference;

use App\DataTransferObjects\Shipping\ServiceInference;
use App\Models\CarrierAlias;
use App\Models\Package;
use App\Services\CarrierNormalizer;

/**
 * Derives the service a package was actually shipped with, where the postage
 * source never reported one.
 *
 * A ladder, cheapest and most reliable first, stopping at the first conclusive
 * answer. Each rung names itself so a value can be re-derived and compared when
 * the tables change. Exhausting every rung is a valid outcome: ADR-0003 decision
 * 8 makes unmapped a terminal state, and a guess written as the service value is
 * the failure this exists to avoid, not a better outcome than silence.
 *
 * The carrier is known before any rung runs, so every rung only has to choose a
 * service within a known carrier. An unrecognised carrier is a clean stop.
 *
 * Nothing here publishes: `Package::confirmedService()` withholds anything that
 * is not `confirmed`, and ADR-0003 decision 7 keeps it that way regardless of how
 * good inference gets.
 */
class ServiceInferrer
{
    public const METHOD_USPS_STC = 'usps-impb-stc';

    public const METHOD_LABEL_TEXT = 'label-text';

    public function __construct(
        private readonly ServiceRuleset $ruleset,
        private readonly LabelTextExtractor $extractor,
        private readonly CarrierNormalizer $normalizer,
    ) {}

    /**
     * Run the ladder over a package without writing anything.
     */
    public function infer(Package $package): ServiceInference
    {
        $carrier = $this->canonicalCarrier($package);

        if ($carrier === null) {
            return ServiceInference::inconclusive('no carrier of record');
        }

        $fromTrackingNumber = $this->fromTrackingNumber($package, $carrier);

        if ($fromTrackingNumber->isResolved()) {
            return $fromTrackingNumber;
        }

        $fromLabel = $this->fromLabelText($package, $carrier);

        if ($fromLabel->isResolved()) {
            return $fromLabel;
        }

        return ServiceInference::inconclusive(
            "tracking number: {$fromTrackingNumber->reason}; label: {$fromLabel->reason}"
        );
    }

    /**
     * Rung 1 — decode the service out of the carrier's own barcode.
     *
     * Costs nothing, needs no label, and is the only rung that survives
     * `PurgePiiCommand`: it nulls `label_data` after the retention period but
     * never touches `tracking_number`, so this is the only rung that can be
     * re-run over historical packages once a ruleset improves.
     */
    private function fromTrackingNumber(Package $package, string $carrier): ServiceInference
    {
        $impb = ImpbTrackingNumber::tryParse($package->tracking_number);

        if (! $impb instanceof ImpbTrackingNumber) {
            return ServiceInference::inconclusive('not a valid IMpb');
        }

        // A valid IMpb under a carrier that is not USPS is a consolidator
        // handing off to USPS for the last mile -- FedEx Ground Economy, UPS
        // Ground Saver, DHL eCommerce. The Service Type Code on those labels
        // names the USPS product carrying the final leg, never the service the
        // customer bought, so decoding it produces a validated wrong answer that
        // no amount of check-digit validation catches. Stop instead.
        if (CarrierAlias::lookupKey($carrier) !== 'usps') {
            return ServiceInference::inconclusive(
                "IMpb under carrier {$carrier}: USPS last-mile handoff, service not encoded"
            );
        }

        $product = $this->ruleset->uspsProductForServiceTypeCode($impb->serviceTypeCode);

        if ($product === null) {
            return ServiceInference::inconclusive("service type code {$impb->serviceTypeCode} names no product");
        }

        return ServiceInference::resolved($product, self::METHOD_USPS_STC, $this->ruleset->version());
    }

    /**
     * Rung 2 — read the service off the label's own plaintext.
     *
     * Has a shelf life. `PurgePiiCommand` nulls `label_data` after
     * `pii_retention_days` because labels carry embedded recipient PII, so this
     * rung must run at purchase time; a package inferred months later may have no
     * label left to read.
     *
     * Matched against a per-carrier token table rather than by scanning for
     * anything service-shaped. A consolidator label prints the USPS product it
     * hands off to alongside its own service — a DHL eCommerce label carries
     * `PS LIGHTWEIGHT` twenty-six fields before it carries `GRD` — so a scan for
     * the first service-looking string is wrong in the same direction rung 1's
     * guard exists to prevent.
     */
    private function fromLabelText(Package $package, string $carrier): ServiceInference
    {
        $tokens = $this->ruleset->labelTokensFor($carrier);

        if ($tokens === []) {
            return ServiceInference::inconclusive("no label tokens for carrier {$carrier}");
        }

        $format = $this->extractor->formatOf($package->label_data);
        $fields = $this->extractor->extract($package->label_data);

        if ($fields === []) {
            return ServiceInference::inconclusive('no readable label text');
        }

        $matches = [];

        foreach ($fields as $field) {
            $normalized = mb_strtoupper(trim((string) preg_replace('/\s+/', ' ', $field)));

            foreach ($tokens as $token => $service) {
                if ($this->fieldNamesToken($normalized, mb_strtoupper($token))) {
                    $matches[$service] = true;
                }
            }
        }

        if ($matches === []) {
            return ServiceInference::inconclusive('no known service token on the label');
        }

        // Two different services on one label is a table that does not describe
        // this carrier's labels properly. Report it rather than picking one.
        if (count($matches) > 1) {
            return ServiceInference::inconclusive(
                'label names more than one service: '.implode(', ', array_keys($matches))
            );
        }

        return ServiceInference::resolved(
            (string) array_key_first($matches),
            self::METHOD_LABEL_TEXT.($format === null ? '' : "-{$format}"),
            $this->ruleset->version(),
        );
    }

    /**
     * The carrier of record, resolved through aliases.
     *
     * A postage source reports the carrier in its own spelling -- Shopify's
     * `trackingInfo.company` says "US Postal Service" where the catalog says
     * "USPS". Comparing the raw string would make the consolidator guard fire on a
     * genuine USPS package and would miss the label token table for every carrier
     * an alias exists for, so both rungs work from the canonical name.
     *
     * Falls back to the raw value, because ADR-0003 decision 8 makes an unmapped
     * carrier a valid terminal state rather than a reason to stop.
     */
    private function canonicalCarrier(Package $package): ?string
    {
        $name = $package->carrierOfRecordName();

        if (blank($name)) {
            return null;
        }

        return $this->normalizer->resolve($name)->name ?? $name;
    }

    /**
     * Whether a label field names exactly this token.
     *
     * Whole-field equality, not a search within the field. A token found inside a
     * longer field is how a shorter service name swallows a longer one --
     * `GROUND` matches `FedEx Ground Economy`, which is not FedEx Ground but
     * FedEx's USPS-last-mile service, so the wrong answer it produces is also the
     * kind rung 1's consolidator guard exists to prevent. A field this table has
     * no exact entry for falls through to `unknown`, which is the correct
     * direction to fail in.
     */
    private function fieldNamesToken(string $field, string $token): bool
    {
        return $field === $token;
    }
}
