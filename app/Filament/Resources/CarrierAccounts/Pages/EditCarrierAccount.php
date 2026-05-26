<?php

namespace App\Filament\Resources\CarrierAccounts\Pages;

use App\Filament\Resources\CarrierAccounts\CarrierAccountResource;
use App\Filament\Resources\CarrierAccounts\Concerns\HasFedexRegistration;
use App\Services\OAuthService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditCarrierAccount extends EditRecord
{
    use HasFedexRegistration;

    protected static string $resource = CarrierAccountResource::class;

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
            // USPS OAuth actions
            Action::make('usps_connect')
                ->label(fn () => app(OAuthService::class)->isAccountConnected($this->record) ? 'Reconnect USPS' : 'Connect USPS')
                ->icon('heroicon-o-link')
                ->color(fn () => app(OAuthService::class)->isAccountConnected($this->record) ? 'warning' : 'primary')
                ->visible(fn () => $this->record->carrier?->name === 'USPS')
                ->disabled(fn () => ! $this->isBrokerConfigured())
                ->tooltip(fn () => ! $this->isBrokerConfigured() ? 'OAuth broker not configured. Set OAUTH_BROKER_URL, OAUTH_BROKER_SECRET, and OAUTH_INSTANCE_ID in .env.' : null)
                ->requiresConfirmation()
                ->modalHeading(fn () => app(OAuthService::class)->isAccountConnected($this->record) ? 'Reconnect USPS' : 'Connect USPS')
                ->modalDescription(fn () => app(OAuthService::class)->isAccountConnected($this->record)
                    ? 'This will replace the existing OAuth token with a new one. You will be redirected to USPS to re-authorize.'
                    : 'You will be redirected to USPS to authorize access.')
                ->action(function (): void {
                    $url = app(OAuthService::class)->initiateAuthorization('usps', $this->record->id);
                    $this->redirect($url, navigate: false);
                }),

            Action::make('usps_disconnect')
                ->label('Disconnect USPS')
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->visible(fn () => $this->record->carrier?->name === 'USPS' && app(OAuthService::class)->isAccountConnected($this->record))
                ->requiresConfirmation()
                ->modalHeading('Disconnect USPS OAuth')
                ->modalDescription('This will remove the OAuth access token. You can reconnect anytime, or the app will fall back to client credentials if configured.')
                ->action(function (): void {
                    app(OAuthService::class)->disconnectAccount($this->record, 'usps');
                    Notification::make()->success()->title('USPS disconnected.')->send();
                    $this->redirect(static::getUrl(['record' => $this->record->id]));
                }),

            // FedEx wizard and disconnect
            $this->fedexRegisterAction(),
            $this->fedexDisconnectAction(),

            // UPS OAuth actions
            Action::make('ups_connect')
                ->label(fn () => app(OAuthService::class)->isAccountConnected($this->record) ? 'Reconnect UPS' : 'Connect UPS')
                ->icon('heroicon-o-link')
                ->color(fn () => app(OAuthService::class)->isAccountConnected($this->record) ? 'warning' : 'primary')
                ->visible(fn () => $this->record->carrier?->name === 'UPS')
                ->disabled(fn () => ! $this->isBrokerConfigured())
                ->tooltip(fn () => ! $this->isBrokerConfigured() ? 'OAuth broker not configured. Set OAUTH_BROKER_URL, OAUTH_BROKER_SECRET, and OAUTH_INSTANCE_ID in .env.' : null)
                ->requiresConfirmation()
                ->modalHeading(fn () => app(OAuthService::class)->isAccountConnected($this->record) ? 'Reconnect UPS' : 'Connect UPS')
                ->modalDescription(fn () => app(OAuthService::class)->isAccountConnected($this->record)
                    ? 'This will replace the existing OAuth token with a new one. You will be redirected to UPS to re-authorize.'
                    : 'You will be redirected to UPS to authorize access.')
                ->action(function (): void {
                    $url = app(OAuthService::class)->initiateAuthorization('ups', $this->record->id);
                    $this->redirect($url, navigate: false);
                }),

            Action::make('ups_disconnect')
                ->label('Disconnect UPS')
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->visible(fn () => $this->record->carrier?->name === 'UPS' && app(OAuthService::class)->isAccountConnected($this->record))
                ->requiresConfirmation()
                ->modalHeading('Disconnect UPS OAuth')
                ->modalDescription('This will remove the OAuth access token. You can reconnect anytime, or the app will fall back to client credentials if configured.')
                ->action(function (): void {
                    app(OAuthService::class)->disconnectAccount($this->record, 'ups');
                    Notification::make()->success()->title('UPS disconnected.')->send();
                    $this->redirect(static::getUrl(['record' => $this->record->id]));
                }),

            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Merge dot-notation credentials (USPS fields) into existing credentials
        // to preserve keys not present in the form (e.g. oauth_connected_at).
        if (isset($data['credentials'])) {
            $data['credentials'] = array_merge($this->record->credentials ?? [], $data['credentials']);
        }

        // Virtual account_number fields for FedEx and UPS
        foreach (['fedex_account_number', 'ups_account_number'] as $field) {
            if (array_key_exists($field, $data)) {
                $data['credentials'] = array_merge(
                    $data['credentials'] ?? $this->record->credentials ?? [],
                    ['account_number' => $data[$field]]
                );
                unset($data[$field]);
            }
        }

        // Virtual secret credential fields — merge into record before fill/save
        $secretMappings = [
            'usps_adv_client_id' => 'client_id',
            'usps_adv_client_secret' => 'client_secret',
            'fedex_adv_api_key' => 'api_key',
            'fedex_adv_api_secret' => 'api_secret',
            'fedex_adv_sandbox_api_key' => 'sandbox_api_key',
            'fedex_adv_sandbox_api_secret' => 'sandbox_api_secret',
            'ups_adv_client_id' => 'client_id',
            'ups_adv_client_secret' => 'client_secret',
        ];

        foreach ($secretMappings as $formField => $secretKey) {
            if (isset($data[$formField]) && filled($data[$formField])) {
                $this->record->mergeSecret($secretKey, $data[$formField]);
            }
            unset($data[$formField]);
        }

        return $data;
    }

    private function isBrokerConfigured(): bool
    {
        return (bool) (config('services.oauth.broker_url')
            && config('services.oauth.broker_secret')
            && config('services.oauth.instance_id'));
    }
}
