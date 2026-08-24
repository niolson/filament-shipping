<?php

namespace App\Filament\Resources\PickBatches\Pages;

use App\Enums\PickBatchStatus;
use App\Filament\Resources\PickBatches\PickBatchResource;
use App\Models\Location;
use App\Models\PickBatch;
use App\Services\GotenbergService;
use App\Services\PickBatchService;
use App\Services\SettingsService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\On;
use Picqer\Barcode\BarcodeGeneratorSVG;
use Throwable;

class ViewPickBatch extends ViewRecord
{
    protected static string $resource = PickBatchResource::class;

    protected string $view = 'filament.resources.pick-batch-resource.pages.view-pick-batch';

    public bool $printMode = false;

    /**
     * Pull fresh batch state when a nested component (the shipments relation manager)
     * completes the batch, so the header actions and detail view stop showing stale status.
     */
    #[On('pick-batch-updated')]
    public function refreshBatch(): void
    {
        $this->record->refresh();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('togglePrintMode')
                ->label(fn () => $this->printMode ? 'Print' : 'View')
                ->icon(fn () => $this->printMode ? 'heroicon-o-printer' : 'heroicon-o-eye')
                ->color('gray')
                ->outlined(fn () => ! $this->printMode)
                ->action(fn () => $this->printMode = ! $this->printMode),

            // View mode — open HTML in a new tab
            Action::make('viewSummary')
                ->label('Picking Summary')
                ->icon('heroicon-o-list-bullet')
                ->visible(fn () => ! $this->printMode)
                ->url(fn () => route('pick-batches.summary', $this->record))
                ->openUrlInNewTab(),

            // Print mode — render PDF via Gotenberg and send to report printer
            Action::make('printSummary')
                ->label('Picking Summary')
                ->icon('heroicon-o-list-bullet')
                ->visible(fn () => $this->printMode)
                ->action(function (): void {
                    $this->printDocument('pick-batches.summary', [
                        'pickBatch' => $this->record,
                        'rows' => app(PickBatchService::class)->summaryRows($this->record),
                    ], function (): void {
                        $this->record->update(['summary_printed_at' => now()]);
                    });
                }),

            // View mode — open HTML in a new tab
            Action::make('viewPackSlips')
                ->label('Pack Slips')
                ->icon('heroicon-o-document-text')
                ->visible(fn () => ! $this->printMode)
                ->url(fn () => route('pick-batches.pack-slips', $this->record))
                ->openUrlInNewTab(),

            // Print mode — render PDF via Gotenberg and send to report printer
            Action::make('printPackSlips')
                ->label('Pack Slips')
                ->icon('heroicon-o-document-text')
                ->visible(fn () => $this->printMode)
                ->action(function (): void {
                    $this->record->load('pickBatchShipments.shipment.shipmentItems.product');

                    $this->printDocument('pick-batches.pack-slips', [
                        'pickBatch' => $this->record,
                        'pivotRows' => $this->record->pickBatchShipments->sortBy('tote_code'),
                        'generator' => new BarcodeGeneratorSVG,
                        'logoDataUri' => $this->resolveLogoDataUri($this->record),
                        'defaultLocation' => Location::getDefault(),
                    ], function (): void {
                        $this->record->pickBatchShipments()->update(['pack_slip_printed_at' => now()]);
                    });
                }),

            Action::make('complete')
                ->label('Mark All Picked')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => $this->record->status === PickBatchStatus::InProgress)
                ->requiresConfirmation()
                ->modalHeading('Mark Batch as Picked')
                ->modalDescription('This will mark all shipments in this batch as picked and complete the batch.')
                ->action(function (): void {
                    app(PickBatchService::class)->complete($this->record);

                    Notification::make()->success()->title('Batch marked as picked.')->send();

                    $this->record->refresh();
                    $this->dispatch('pick-batch-updated');
                }),

            Action::make('cancel')
                ->label('Cancel Batch')
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->visible(fn () => $this->record->status === PickBatchStatus::InProgress)
                ->requiresConfirmation()
                ->modalHeading('Cancel Pick Batch')
                ->modalDescription('This will cancel the batch and return all shipments to pending picking status.')
                ->action(function (): void {
                    app(PickBatchService::class)->cancel($this->record);

                    Notification::make()->success()->title('Pick batch cancelled.')->send();

                    $this->record->refresh();
                    $this->dispatch('pick-batch-updated');
                }),
        ];
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

    /**
     * Render a view to PDF via Gotenberg, dispatch to QZ Tray, and call $onSuccess if it worked.
     *
     * @param  array<string, mixed>  $data
     */
    private function printDocument(string $view, array $data, callable $onSuccess): void
    {
        try {
            $pdf = app(GotenbergService::class)->pdfFromView($view, $data);
            $this->dispatch('print-report', data: base64_encode($pdf));
            $onSuccess();
        } catch (Throwable $e) {
            Notification::make()
                ->danger()
                ->title('PDF renderer unavailable')
                ->body($e->getMessage())
                ->send();
        }
    }
}
