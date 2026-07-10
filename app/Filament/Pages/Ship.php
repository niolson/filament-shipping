<?php

namespace App\Filament\Pages;

use App\Contracts\PackageShippingWorkflow;
use App\DataTransferObjects\PackageShipping\PackageShippingOptions;
use App\DataTransferObjects\PackageShipping\PackageShippingRequest;
use App\DataTransferObjects\Shipping\RateResponse;
use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Filament\Concerns\NotifiesUser;
use App\Filament\Concerns\PrintsLabels;
use App\Models\Package;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;

class Ship extends Page
{
    use NotifiesUser;
    use PrintsLabels;

    private const RATE_CACHE_SECONDS = 60;

    /**
     * Allow a full carrier request to finish before another request may take
     * over quote generation for the same package.
     */
    private const RATE_LOCK_SECONDS = 75;

    private const RATE_LOCK_WAIT_SECONDS = 70;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-paper-airplane';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'ship/{package_id?}';

    public static function canAccess(): bool
    {
        return auth()->user()?->role->isAtLeast(Role::User) ?? false;
    }

    protected string $view = 'filament.pages.ship';

    public ?Package $package = null;

    /** @var array<int, array<string, mixed>> */
    public array $rateOptions = [];

    public array $formRateOptionDescriptions = [];

    public ?string $deliverByDate = null;

    public bool $allRatesLate = false;

    public string $labelFormat = 'pdf';

    public ?int $labelDpi = null;

    public ?int $selectedRateIndex = null;

    public string $returnUrl = '/pack';

    public bool $overrideCustomsWeights = false;

    public function mount($package_id = null): void
    {
        $this->returnUrl = Session::pull('ship_return_url', '/pack');

        if (! $package_id) {
            $this->redirect($this->returnUrl);

            return;
        }

        $this->package = Package::with(['packageItems.product', 'packageItems.shipmentItem', 'shipment.shippingMethod', 'boxSize'])->findOrFail($package_id);

        $this->authorize('ship', $this->package);

        if ($this->package->status === PackageStatus::Shipped) {
            $this->notifyWarning('Already Shipped', 'This package has already been shipped.');
            $this->redirect($this->returnUrl);

            return;
        }

        try {
            $options = $this->prepareCachedRates();
        } catch (LockTimeoutException) {
            $this->notifyWarning('Rates are being refreshed', 'Please wait a moment and try again.');

            return;
        }

        if ($options->blockingError) {
            $this->notifyError('Declared Value Required', $options->blockingError);
        }

        foreach ($options->exclusions as $exclusion) {
            $this->notifyWarning($exclusion['carrier'].' excluded', $exclusion['reason']);
        }

        $this->applyRateOptions($options);
    }

    protected function getHeaderActions(): array
    {
        if (! $this->package) {
            return [];
        }

        return [
            Action::make('Ship')
                ->action(fn () => $this->ship())
                ->icon('heroicon-o-printer')
                ->keybindings(['f12'])
                ->disabled(fn () => empty($this->rateOptions) || $this->selectedRateIndex === null),
            Action::make('Back')
                ->action(fn () => $this->redirect($this->returnUrl))
                ->icon('heroicon-o-arrow-left')
                ->color('gray'),
        ];
    }

    public function refreshRates(): void
    {
        if (! $this->package) {
            return;
        }

        // Explicit refresh fires fresh carrier calls; cap the rate per user so it
        // can't be scripted into hammering the carriers (or PolyBag). See issue 09.
        $throttleKey = 'ship-refresh-rates:'.(auth()->id() ?? 'guest');

        if (RateLimiter::tooManyAttempts($throttleKey, maxAttempts: 15)) {
            $this->notifyWarning(
                'Slow down',
                'Too many rate refreshes. Please wait '.RateLimiter::availableIn($throttleKey).'s and try again.',
            );

            return;
        }

        RateLimiter::hit($throttleKey, decaySeconds: 60);

        // Invalidate before acquiring the per-package lock. The request that
        // acquires it obtains fresh rates; concurrent refreshes wait and reuse
        // that fresh result instead of calling carriers in parallel.
        Cache::forget($this->rateCacheKey());

        try {
            $options = $this->prepareCachedRates();
        } catch (LockTimeoutException) {
            $this->notifyWarning('Rates are being refreshed', 'Please wait a moment and try again.');

            return;
        }

        if ($options->blockingError) {
            $this->notifyError('Declared Value Required', $options->blockingError);
        }

        $this->applyRateOptions($options);
    }

    /**
     * Cache key for a package's prepared rates, tied to the package/shipment state
     * so an edit to either produces a fresh quote rather than serving a stale one.
     */
    private function rateCacheKey(): string
    {
        return implode(':', [
            'ship-rates',
            $this->package->id,
            $this->package->updated_at->timestamp,
            $this->package->shipment?->updated_at->timestamp ?? 0,
        ]);
    }

    /**
     * Return a cached quote or have exactly one request obtain and store a new
     * quote for this package. Cache::remember() alone is not single-flight: on
     * a cold cache, concurrent requests would all invoke the carrier workflow.
     */
    private function prepareCachedRates(): PackageShippingOptions
    {
        $cacheKey = $this->rateCacheKey();
        $cached = Cache::get($cacheKey);

        if ($cached instanceof PackageShippingOptions) {
            return $cached;
        }

        return Cache::lock("{$cacheKey}:lock", self::RATE_LOCK_SECONDS)->block(
            self::RATE_LOCK_WAIT_SECONDS,
            function () use ($cacheKey): PackageShippingOptions {
                // The request that held the lock may have completed while this
                // request waited, so always check again after acquiring it.
                $cached = Cache::get($cacheKey);

                if ($cached instanceof PackageShippingOptions) {
                    return $cached;
                }

                $options = app(PackageShippingWorkflow::class)->prepareRates($this->package);

                Cache::put($cacheKey, $options, now()->addSeconds(self::RATE_CACHE_SECONDS));

                return $options;
            },
        );
    }

    private function applyRateOptions(PackageShippingOptions $options): void
    {
        $this->rateOptions = $options->rateOptions;
        $this->formRateOptionDescriptions = $options->rateOptionDescriptions;
        $this->deliverByDate = $options->deliverByDate;
        $this->allRatesLate = $options->allRatesLate;
        $this->selectedRateIndex = $options->selectedRateIndex;
    }

    public function ship(): void
    {
        if ($this->selectedRateIndex === null || ! isset($this->rateOptions[$this->selectedRateIndex])) {
            $this->notifyError('No Rate Selected', 'Please select a shipping rate.');

            return;
        }

        $rate = $this->rateOptions[$this->selectedRateIndex];
        $selectedRate = RateResponse::fromArray($rate);

        $result = app(PackageShippingWorkflow::class)->ship(
            $this->package,
            new PackageShippingRequest(
                selectedRate: $selectedRate,
                labelFormat: $this->labelFormat,
                labelDpi: $this->labelDpi,
                overrideCustomsWeights: $this->overrideCustomsWeights,
                userId: auth()->id(),
            ),
        );

        if ($result->requiresCustomsWeightOverride) {
            $this->dispatch('open-modal', id: 'customs-weight-override');

            return;
        }

        $this->overrideCustomsWeights = false;

        if (! $result->success) {
            $this->notifyError($result->title ?? 'Shipping Error', $result->message ?? 'An unexpected error occurred. Please try again.');

            return;
        }

        $this->notifySuccess($result->title ?? 'Package Shipped', $result->message ?? 'Package shipped.');

        if ($result->printRequest) {
            $this->dispatchPrint($result->printRequest, redirectTo: $this->returnUrl);
        } else {
            $this->redirect($this->returnUrl);
        }
    }

    public function confirmCustomsWeightOverride(): void
    {
        $this->overrideCustomsWeights = true;
        $this->dispatch('close-modal', id: 'customs-weight-override');
        $this->ship();
    }
}
