<?php

namespace App\Http\Controllers;

use App\Models\PickBatch;
use App\Services\PickBatchService;
use Illuminate\View\View;
use Picqer\Barcode\BarcodeGeneratorSVG;

class PickBatchController extends Controller
{
    public function summary(PickBatch $pickBatch): View
    {
        $rows = app(PickBatchService::class)->summaryRows($pickBatch);

        return view('pick-batches.summary', compact('pickBatch', 'rows'));
    }

    public function packSlips(PickBatch $pickBatch): View
    {
        $pickBatch->load('pickBatchShipments.shipment.shipmentItems.product');

        $generator = new BarcodeGeneratorSVG;
        $pivotRows = $pickBatch->pickBatchShipments->sortBy('tote_code');

        return view('pick-batches.pack-slips', compact('pickBatch', 'pivotRows', 'generator'));
    }
}
