<?php

namespace App\Services;

use App\Enums\LabelReferenceSource;
use App\Models\Package;

/**
 * Resolves the identifier a package's label should carry so a printed label can
 * be matched back to its package — most useful when a batch prints a stack of
 * labels at once.
 *
 * The source is an instance-wide setting that a client may override; a client
 * with no choice of its own follows the instance.
 *
 * The values returned here are carrier-agnostic. Each adapter is responsible for
 * truncating them to its own field limits.
 */
class LabelReferenceResolver
{
    /**
     * Used until an instance sets its own default in App Settings.
     */
    public const DEFAULT_SOURCE = LabelReferenceSource::ShipmentReference;

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @return array<int, string>
     */
    public function forPackage(Package $package): array
    {
        $package->loadMissing('shipment.client');

        $shipment = $package->shipment;

        if ($shipment === null) {
            return [];
        }

        $source = $shipment->client->label_reference_source ?? $this->instanceDefault();

        $value = match ($source) {
            LabelReferenceSource::None => null,
            LabelReferenceSource::ShipmentReference => $shipment->shipment_reference,
            LabelReferenceSource::ShipmentId => (string) $shipment->id,
            LabelReferenceSource::PackageId => (string) $package->id,
        };

        $value = $this->normalize($value);

        return $value === null ? [] : [$value];
    }

    /**
     * The instance-wide default, applied to every client that has not picked a
     * source of its own.
     */
    public function instanceDefault(): LabelReferenceSource
    {
        $configured = $this->settings->get('label_reference_source');

        return LabelReferenceSource::tryFrom((string) $configured) ?? self::DEFAULT_SOURCE;
    }

    /**
     * Collapse whitespace and drop control characters, which carriers reject or
     * silently mangle on the printed label.
     */
    private function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', preg_replace('/[^\P{C}]/u', '', $value) ?? ''));

        return $value === '' ? null : $value;
    }
}
