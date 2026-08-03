<?php

use App\Enums\LabelBatchStatus;
use App\Enums\PackageStatus;
use App\Models\BoxSize;
use App\Models\LabelBatch;
use App\Models\LabelBatchItem;
use App\Models\Package;
use App\Models\User;
use Carbon\Carbon;

it('creates a label batch with factory', function (): void {
    $batch = LabelBatch::factory()->create(['total_shipments' => 5]);

    expect($batch)->toBeInstanceOf(LabelBatch::class)
        ->and($batch->total_shipments)->toBe(5)
        ->and($batch->status)->toBe(LabelBatchStatus::Pending);
});

it('has user relation', function (): void {
    $user = User::factory()->create();
    $batch = LabelBatch::factory()->create(['user_id' => $user->id]);

    expect($batch->user->id)->toBe($user->id);
});

it('has boxSize relation', function (): void {
    $boxSize = BoxSize::factory()->create();
    $batch = LabelBatch::factory()->create(['box_size_id' => $boxSize->id]);

    expect($batch->boxSize->id)->toBe($boxSize->id);
});

it('has items relation', function (): void {
    $batch = LabelBatch::factory()->create();
    $item = LabelBatchItem::factory()->create(['label_batch_id' => $batch->id]);

    expect($batch->items)->toHaveCount(1)
        ->and($batch->items->first()->id)->toBe($item->id);
});

it('isComplete returns true for completed statuses', function (LabelBatchStatus $status, bool $expected): void {
    $batch = LabelBatch::factory()->create(['status' => $status]);

    expect($batch->isComplete())->toBe($expected);
})->with([
    [LabelBatchStatus::Pending, false],
    [LabelBatchStatus::Processing, false],
    [LabelBatchStatus::Completed, true],
    [LabelBatchStatus::CompletedWithErrors, true],
    [LabelBatchStatus::Failed, true],
]);

it('casts status to LabelBatchStatus enum', function (): void {
    $batch = LabelBatch::factory()->create(['status' => LabelBatchStatus::Processing]);

    expect($batch->status)->toBe(LabelBatchStatus::Processing);
});

it('casts timestamps correctly', function (): void {
    $batch = LabelBatch::factory()->processing()->create();

    expect($batch->started_at)->toBeInstanceOf(Carbon::class);
});

it('counts only successful items whose label has not been printed', function (): void {
    $batch = LabelBatch::factory()->create();

    $printed = Package::factory()->shipped()->create(['label_printed_at' => now()]);
    $unprinted = Package::factory()->shipped()->create(['label_printed_at' => null]);
    $failedItemPackage = Package::factory()->create(['label_printed_at' => null]);

    LabelBatchItem::factory()->success()->create([
        'label_batch_id' => $batch->id,
        'package_id' => $printed->id,
    ]);
    LabelBatchItem::factory()->success()->create([
        'label_batch_id' => $batch->id,
        'package_id' => $unprinted->id,
    ]);
    LabelBatchItem::factory()->failed()->create([
        'label_batch_id' => $batch->id,
        'package_id' => $failedItemPackage->id,
    ]);

    expect($batch->unprintedCount())->toBe(1)
        ->and($batch->printedCount())->toBe(1)
        ->and($batch->unprintedItems()->first()->package_id)->toBe($unprinted->id);
});

it('drops items whose label was voided after the batch ran', function (): void {
    $batch = LabelBatch::factory()->create();

    $voided = Package::factory()->create([
        'status' => PackageStatus::Unshipped,
        'label_data' => null,
        'label_printed_at' => null,
    ]);
    $stillPrintable = Package::factory()->shipped()->create(['label_printed_at' => null]);

    foreach ([$voided, $stillPrintable] as $package) {
        LabelBatchItem::factory()->success()->create([
            'label_batch_id' => $batch->id,
            'package_id' => $package->id,
        ]);
    }

    expect($batch->printableItems()->count())->toBe(1)
        ->and($batch->unprintedCount())->toBe(1)
        ->and($batch->unprintedItems()->first()->package_id)->toBe($stillPrintable->id);
});
