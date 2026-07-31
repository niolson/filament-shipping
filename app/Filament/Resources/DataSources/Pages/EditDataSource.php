<?php

namespace App\Filament\Resources\DataSources\Pages;

use App\Filament\Resources\DataSources\DataSourceResource;
use App\Jobs\RunDataSourceImportJob;
use App\Models\DataSource;
use App\Models\Shipment;
use App\Services\OAuthService;
use App\Services\SettingsService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use App\Services\ShipmentImport\Sources\ShopifySource;
use App\Services\ShopifyFulfillmentOrderActivationService;
use App\Services\ShopifyLocationSynchronizer;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Throwable;

class EditDataSource extends EditRecord
{
    protected static string $resource = DataSourceResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if ($notification = session('oauth_notification')) {
            Notification::make()
                ->{$notification['status']}()
                ->title($notification['title'])
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('run_import')
                ->label('Run Import Now')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->disabled(fn (): bool => ! $this->record->active)
                ->tooltip(fn (): ?string => $this->record->active ? null : 'This source is inactive. Activate it to run imports.')
                ->requiresConfirmation()
                ->modalHeading('Run import now?')
                ->modalDescription('Fetch new shipments from this source in the background. You will receive a notification when it finishes.')
                ->action(function (): void {
                    RunDataSourceImportJob::dispatch($this->record->id, auth()->id());

                    Notification::make()
                        ->info()
                        ->title('Import queued')
                        ->body("You'll be notified when \"{$this->record->name}\" finishes importing.")
                        ->send();
                }),

            Action::make('shopify_connect')
                ->label(fn () => app(OAuthService::class)->isDataSourceConnected($this->record) ? 'Reconnect Shopify' : 'Connect with OAuth')
                ->icon('heroicon-o-link')
                ->color(fn () => app(OAuthService::class)->isDataSourceConnected($this->record) ? 'warning' : 'primary')
                ->visible(fn () => $this->record->source_type === ShopifySource::class)
                ->disabled(fn () => ! $this->isBrokerConfigured())
                ->tooltip(fn () => ! $this->isBrokerConfigured() ? 'OAuth broker not configured. Set OAUTH_BROKER_URL, OAUTH_BROKER_SECRET, and OAUTH_INSTANCE_ID in .env.' : null)
                ->requiresConfirmation()
                ->modalHeading(fn () => app(OAuthService::class)->isDataSourceConnected($this->record) ? 'Reconnect Shopify' : 'Connect Shopify')
                ->modalDescription(fn () => app(OAuthService::class)->isDataSourceConnected($this->record)
                    ? 'This will replace the existing OAuth token. You will be redirected to Shopify to re-authorize.'
                    : 'You will be redirected to Shopify to authorize access. Make sure Shop Domain is saved first.')
                ->action(function (): void {
                    $url = app(OAuthService::class)->initiateAuthorizationForDataSource('shopify', $this->record);
                    $this->redirect($url, navigate: false);
                }),

            Action::make('shopify_disconnect')
                ->label('Disconnect Shopify')
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->visible(fn () => $this->record->source_type === ShopifySource::class && app(OAuthService::class)->isDataSourceConnected($this->record))
                ->requiresConfirmation()
                ->modalHeading('Disconnect Shopify OAuth')
                ->modalDescription('This will remove the OAuth access token. The source will fall back to the custom access token or client credentials flow.')
                ->action(function (): void {
                    app(OAuthService::class)->disconnectDataSource('shopify', $this->record);
                    Notification::make()->success()->title('Shopify disconnected.')->send();
                    $this->redirect(static::getUrl(['record' => $this->record->id]));
                }),

            Action::make('sync_shopify_locations')
                ->label('Sync Shopify Locations')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (): bool => $this->dataSource()->source_type === ShopifySource::class)
                ->action(function (): void {
                    try {
                        $dataSource = $this->dataSource();
                        $result = app(ShopifyLocationSynchronizer::class)->synchronize($dataSource);

                        Notification::make()
                            ->success()
                            ->title('Shopify locations synchronized')
                            ->body("Found {$result['synced']} active locations; {$result['deactivated']} previously known locations marked inactive; {$result['auto_mapped']} auto-mapped.")
                            ->send();

                        $this->redirect(static::getUrl(['record' => $dataSource->id]));
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->danger()
                            ->title('Shopify location sync failed')
                            ->body($exception->getMessage())
                            ->send();
                    }
                }),

            Action::make('activate_fulfillment_order_import')
                ->label('Activate Fulfillment-Order Import')
                ->icon('heroicon-o-check-circle')
                ->color('warning')
                ->visible(fn (): bool => $this->dataSource()->source_type === ShopifySource::class
                    && ! ($this->dataSource()->settings['fulfillment_order_import_enabled'] ?? false))
                ->requiresConfirmation()
                ->modalHeading('Activate fulfillment-order imports?')
                ->modalDescription('This one-way change imports one Shipment per Shopify fulfillment order. It cannot be switched back because doing so could create duplicate work.')
                ->action(function (): void {
                    try {
                        $dataSource = $this->dataSource();
                        app(ShopifyFulfillmentOrderActivationService::class)->activate($dataSource);

                        Notification::make()
                            ->success()
                            ->title('Fulfillment-order imports activated')
                            ->send();

                        $this->redirect(static::getUrl(['record' => $dataSource->id]));
                    } catch (DomainException $exception) {
                        Notification::make()
                            ->danger()
                            ->title('Activation requirements not met')
                            ->body($exception->getMessage())
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->danger()
                            ->title('Shopify scope validation failed')
                            ->body($exception->getMessage())
                            ->send();
                    }
                }),

            DeleteAction::make(),
        ];
    }

    /**
     * Split secret keys into the encrypted column; preserve existing values for
     * fields left blank (password fields that weren't re-entered).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->validateMfaRequiredForAmazon($data);

        $record = $this->getRecord();
        $existing = $record->settings ?? [];
        $existingSecrets = $record->secret_settings ?? [];
        $submitted = $data['settings'] ?? [];

        // Migrate any legacy secrets still sitting in the plain settings column.
        foreach (DataSource::SECRET_SETTINGS_KEYS as $key) {
            if (isset($existing[$key])) {
                $existingSecrets[$key] = $existing[$key];
                unset($existing[$key]);
            }
        }

        // Route newly-submitted secret keys to secret_settings; preserve existing for blanks.
        foreach (DataSource::SECRET_SETTINGS_KEYS as $key) {
            if (array_key_exists($key, $submitted) && filled($submitted[$key])) {
                $existingSecrets[$key] = $submitted[$key];
            }
            unset($submitted[$key]);
        }

        // Preserve non-secret existing settings for keys not in submission.
        foreach ($existing as $key => $value) {
            if (! array_key_exists($key, $submitted)) {
                $submitted[$key] = $value;
            }
        }

        $data['settings'] = $submitted;
        $data['secret_settings'] = $existingSecrets ?: null;

        return $data;
    }

    protected function afterSave(): void
    {
        $dataSource = $this->dataSource();

        if ($dataSource->source_type !== ShopifySource::class) {
            return;
        }

        $preservedConflicts = Shipment::query()
            ->join('data_source_locations', 'data_source_locations.id', '=', 'shipments.data_source_location_id')
            ->where('shipments.data_source_id', $dataSource->id)
            ->whereNotNull('data_source_locations.location_id')
            ->where(function ($query): void {
                $query->whereNull('shipments.location_id')
                    ->orWhereColumn('shipments.location_id', '!=', 'data_source_locations.location_id');
            })
            ->whereHas('packages')
            ->count('shipments.id');

        if ($preservedConflicts > 0) {
            Notification::make()
                ->warning()
                ->title('Packed shipments preserved')
                ->body("{$preservedConflicts} Shipments already have a Package and were not moved to the new mapping. Resolve open location conflicts manually; shipped history remains unchanged.")
                ->send();
        }
    }

    private function dataSource(): DataSource
    {
        $record = $this->getRecord();

        if (! $record instanceof DataSource) {
            throw new \LogicException('The Data Source record is unavailable.');
        }

        return $record;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateMfaRequiredForAmazon(array $data): void
    {
        if (($data['source_type'] ?? null) !== AmazonSource::class || ! ($data['active'] ?? false)) {
            return;
        }

        if (app(SettingsService::class)->get('require_mfa', false)) {
            return;
        }

        $message = 'Amazon SP-API sources give access to customer PII, so Multi-Factor Authentication must be required for all users before this source can be active. Enable it in App Settings → Authentication first.';

        Notification::make()
            ->title('Multi-Factor Authentication required')
            ->body($message)
            ->danger()
            ->send();

        $this->addError('data.active', $message);

        throw new Halt;
    }

    private function isBrokerConfigured(): bool
    {
        return (bool) (config('services.oauth.broker_url')
            && config('services.oauth.broker_secret')
            && config('services.oauth.instance_id'));
    }
}
