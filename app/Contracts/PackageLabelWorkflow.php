<?php

namespace App\Contracts;

use App\DataTransferObjects\PackageLabels\LabelReprintResult;
use App\DataTransferObjects\PackageLabels\LabelVoidResult;
use App\Models\Package;
use App\Models\User;

interface PackageLabelWorkflow
{
    public function voidLabel(Package $package): LabelVoidResult;

    public function labelForReprint(Package $package, User $user): LabelReprintResult;

    /**
     * Record that a label physically reached a printer.
     *
     * @return bool Whether this was a reprint (the label had been printed before)
     */
    public function markLabelPrinted(Package $package, ?User $user = null): bool;
}
