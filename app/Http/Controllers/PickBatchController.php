<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\PickBatch;
use App\Services\PickBatchService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Storage;
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
        $logoDataUri = $this->resolveLogoDataUri($pickBatch);
        $defaultLocation = Location::getDefault();

        return view('pick-batches.pack-slips', compact('pickBatch', 'pivotRows', 'generator', 'logoDataUri', 'defaultLocation'));
    }

    private function resolveLogoDataUri(PickBatch $pickBatch): ?string
    {
        $path = $pickBatch->client?->logo
            ?? app(SettingsService::class)->get('pack_slip_logo');

        if (blank($path) || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $content = Storage::disk('public')->get($path);
        $mimeType = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'svg' => 'image/svg+xml',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/png',
        };

        return 'data:'.$mimeType.';base64,'.base64_encode($content);
    }
}
