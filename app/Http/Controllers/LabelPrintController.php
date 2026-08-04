<?php

namespace App\Http\Controllers;

use App\Contracts\PackageLabelWorkflow;
use App\Enums\PackageStatus;
use App\Models\Package;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LabelPrintController extends Controller
{
    public function __construct(
        private readonly PackageLabelWorkflow $labelWorkflow,
    ) {}

    /**
     * Record that a package's label reached a printer.
     *
     * Called by the QZ Tray integration once a print job is accepted, for both
     * single labels and each label in a batch print.
     *
     * Gated by the same policy as printing the label in the first place, so a user
     * who could not have triggered the print cannot clear a package out of another
     * operator's unprinted queue or plant a false audit entry.
     */
    public function store(Request $request, Package $package): JsonResponse
    {
        if ($request->user()->cannot('printLabel', $package)) {
            return response()->json(['error' => 'You cannot record prints for this package.'], 403);
        }

        if ($package->status !== PackageStatus::Shipped || ! $package->label_data) {
            return response()->json(['error' => 'Package has no printable label.'], 422);
        }

        $wasReprint = $this->labelWorkflow->markLabelPrinted($package, $request->user());

        return response()->json([
            'printed_at' => $package->label_printed_at?->toIso8601String(),
            'reprint' => $wasReprint,
        ]);
    }
}
