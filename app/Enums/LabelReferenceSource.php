<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Which package identifier is sent to the carrier to be printed on the label.
 */
enum LabelReferenceSource: string implements HasLabel
{
    /** Nothing is sent; labels print without a reference. */
    case None = 'none';

    /** The shipment reference, i.e. the order number the client knows the order by. */
    case ShipmentReference = 'shipment_reference';

    /** The internal shipment ID. */
    case ShipmentId = 'shipment_id';

    /** The internal package ID, which is unique per label on multi-package shipments. */
    case PackageId = 'package_id';

    public function getLabel(): string
    {
        return match ($this) {
            self::None => 'No reference',
            self::ShipmentReference => 'Shipment reference',
            self::ShipmentId => 'Shipment ID',
            self::PackageId => 'Package ID',
        };
    }
}
