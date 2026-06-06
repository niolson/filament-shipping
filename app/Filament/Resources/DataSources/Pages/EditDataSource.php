<?php

namespace App\Filament\Resources\DataSources\Pages;

use App\Filament\Resources\DataSources\DataSourceResource;
use App\Models\DataSource;
use App\Services\OAuthService;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

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
            Action::make('shopify_connect')
                ->label(fn () => app(OAuthService::class)->isDataSourceConnected($this->record) ? 'Reconnect Shopify' : 'Connect with OAuth')
                ->icon('heroicon-o-link')
                ->color(fn () => app(OAuthService::class)->isDataSourceConnected($this->record) ? 'warning' : 'primary')
                ->visible(fn () => $this->record->driver === ShopifySource::class)
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
                ->visible(fn () => $this->record->driver === ShopifySource::class && app(OAuthService::class)->isDataSourceConnected($this->record))
                ->requiresConfirmation()
                ->modalHeading('Disconnect Shopify OAuth')
                ->modalDescription('This will remove the OAuth access token. The source will fall back to the custom access token or client credentials flow.')
                ->action(function (): void {
                    app(OAuthService::class)->disconnectDataSource('shopify', $this->record);
                    Notification::make()->success()->title('Shopify disconnected.')->send();
                    $this->redirect(static::getUrl(['record' => $this->record->id]));
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

    private function isBrokerConfigured(): bool
    {
        return (bool) (config('services.oauth.broker_url')
            && config('services.oauth.broker_secret')
            && config('services.oauth.instance_id'));
    }
}
