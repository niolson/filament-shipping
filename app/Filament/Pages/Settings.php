<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Http\Integrations\USPS\Requests\ShippingOptions;
use App\Http\Integrations\USPS\USPSConnector;
use App\Models\Location;
use App\Models\Setting;
use App\Services\SettingsService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\HasUnsavedDataChangesAlert;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Cache;
use Saloon\Exceptions\Request\Statuses\ForbiddenException;
use UnitEnum;

/**
 * @property-read Schema $form
 */
class Settings extends Page
{
    use HasUnsavedDataChangesAlert;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationLabel = 'App Settings';

    protected static UnitEnum|string|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 99;

    protected string $view = 'filament.pages.settings';

    public static function canAccess(): bool
    {
        return auth()->user()->role->isAtLeast(Role::Admin);
    }

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'company_name' => app(SettingsService::class)->get('company_name', ''),
            'pack_slip_logo' => app(SettingsService::class)->get('pack_slip_logo'),
            'packing_validation_enabled' => app(SettingsService::class)->get('packing_validation_enabled', true),
            'transparency_enabled' => app(SettingsService::class)->get('transparency_enabled', true),
            'batch_shipping_enabled' => app(SettingsService::class)->get('batch_shipping_enabled', true),
            'manual_shipping_enabled' => app(SettingsService::class)->get('manual_shipping_enabled', true),
            'picking_enabled' => app(SettingsService::class)->get('picking_enabled', false),
            'multi_location_enabled' => app(SettingsService::class)->get('multi_location_enabled', false),
            'multi_client_enabled' => app(SettingsService::class)->get('multi_client_enabled', false),
            'carrier_api_timeout' => app(SettingsService::class)->get('carrier_api_timeout', 15),
            'audit_log_retention_days' => app(SettingsService::class)->get('audit_log_retention_days', 365),
            'rate_quote_retention_days' => app(SettingsService::class)->get('rate_quote_retention_days', 60),
            'pii_retention_days' => app(SettingsService::class)->get('pii_retention_days', 90),
            'archiving_enabled' => app(SettingsService::class)->get('archiving_enabled', false),
            'archive_retention_days' => app(SettingsService::class)->get('archive_retention_days', 365),
            'password_min_length' => app(SettingsService::class)->get('password_min_length', 8),
            'password_require_mixed_case' => app(SettingsService::class)->get('password_require_mixed_case', true),
            'password_require_numbers' => app(SettingsService::class)->get('password_require_numbers', true),
            'password_require_symbols' => app(SettingsService::class)->get('password_require_symbols', false),
            'password_expiration_days' => app(SettingsService::class)->get('password_expiration_days', 0),
            'google_sso_enabled' => app(SettingsService::class)->get('google_sso_enabled', false),
            'sandbox_mode' => app(SettingsService::class)->get('sandbox_mode', false),
            'suppress_printing' => app(SettingsService::class)->get('suppress_printing', false),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make('Company Information')
                        ->description('General company settings')
                        ->schema([
                            TextInput::make('company_name')
                                ->label('Company Name')
                                ->maxLength(255),
                            FileUpload::make('pack_slip_logo')
                                ->label('Pack Slip Logo')
                                ->helperText('Logo printed on pack slips. Recommended: landscape image, PNG or JPG.')
                                ->disk('public')
                                ->directory('logos')
                                ->visibility('public')
                                ->image()
                                ->panelLayout('grid')
                                ->maxSize(10240)
                                ->acceptedFileTypes(['image/svg+xml', 'image/png', 'image/jpeg', 'image/gif', 'image/webp']),
                        ])
                        ->columns(1),

                    Section::make('Ship-From Address')
                        ->description('Managed in Settings > Locations. The default location is used as the return address on labels.')
                        ->schema([
                            Placeholder::make('default_location')
                                ->label('Default Location')
                                ->content(fn () => Location::getDefault()?->name ?? 'No default location set'),
                        ]),

                    Section::make('Features')
                        ->description('Enable or disable application features')
                        ->schema([
                            Toggle::make('packing_validation_enabled')
                                ->label('Packing Validation')
                                ->helperText('When enabled, all items must be scanned before shipping. When disabled, only weight and dimensions are required.')
                                ->default(true),
                            Toggle::make('batch_shipping_enabled')
                                ->label('Batch Shipping')
                                ->helperText('When enabled, admins can select multiple shipments and generate labels in bulk.')
                                ->default(true),
                            Toggle::make('manual_shipping_enabled')
                                ->label('Manual Shipping')
                                ->helperText('When enabled, the Manual Ship page is available for creating ad-hoc shipments.')
                                ->default(true),
                            Toggle::make('picking_enabled')
                                ->label('Picking')
                                ->helperText('When enabled, pickers can create pick batches and print picking summaries before packing.')
                                ->default(false),
                            Toggle::make('multi_location_enabled')
                                ->label('Multi-Location')
                                ->helperText('When enabled, packages are tracked per warehouse and end-of-day processes run per location. Each user can be assigned a default location.')
                                ->default(false),
                            Toggle::make('multi_client_enabled')
                                ->label('Multi-Client (3PL)')
                                ->helperText('When enabled, shipments, products, and shipping rules are scoped per client. Client columns and selectors appear across the UI.')
                                ->default(false),
                            Toggle::make('transparency_enabled')
                                ->label('Amazon Transparency Program')
                                ->helperText('When enabled, shipment items requiring transparency codes will prompt for code scanning during packing.')
                                ->default(false),
                        ])
                        ->columns(1),

                    Section::make('Carrier API')
                        ->description('Settings for carrier API requests')
                        ->schema([
                            TextInput::make('carrier_api_timeout')
                                ->label('Request Timeout (seconds)')
                                ->helperText('Maximum time to wait for a response from carrier APIs (USPS, FedEx, UPS). Default is 15 seconds.')
                                ->numeric()
                                ->minValue(5)
                                ->maxValue(60)
                                ->default(15)
                                ->suffix('seconds'),
                        ])
                        ->columns(1),

                    Section::make('Password Policy')
                        ->description('Password requirements for local accounts')
                        ->schema([
                            TextInput::make('password_min_length')
                                ->label('Minimum Length')
                                ->numeric()
                                ->minValue(8)
                                ->maxValue(128)
                                ->default(8)
                                ->suffix('characters'),
                            Toggle::make('password_require_mixed_case')
                                ->label('Require Mixed Case')
                                ->helperText('Require at least one uppercase and one lowercase letter.')
                                ->default(true),
                            Toggle::make('password_require_numbers')
                                ->label('Require Numbers')
                                ->helperText('Require at least one numeric character.')
                                ->default(true),
                            Toggle::make('password_require_symbols')
                                ->label('Require Symbols')
                                ->helperText('Require at least one special character.')
                                ->default(false),
                            TextInput::make('password_expiration_days')
                                ->label('Password Expiration')
                                ->helperText('Force users to change passwords after this many days. Set to 0 to disable.')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(3650)
                                ->default(0)
                                ->suffix('days'),
                        ])
                        ->columns(2),

                    Section::make('Single Sign-On')
                        ->description('Allow users to sign in with external identity providers')
                        ->schema([
                            Toggle::make('google_sso_enabled')
                                ->label('Google SSO')
                                ->helperText('Show "Sign in with Google" button on the login page. Requires Google OAuth credentials in .env.')
                                ->default(false),
                        ])
                        ->columns(1),

                    Section::make('Data Retention')
                        ->description('Configure how long data is kept before automatic cleanup')
                        ->schema([
                            TextInput::make('audit_log_retention_days')
                                ->label('Audit Log Retention')
                                ->helperText('Audit log entries older than this will be automatically purged daily. Set to 0 to disable.')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(3650)
                                ->default(365)
                                ->suffix('days'),
                            TextInput::make('rate_quote_retention_days')
                                ->label('Rate Quote Retention')
                                ->helperText('Rate quotes older than this will be automatically purged daily. The selected rate is always preserved on the package. Set to 0 to disable.')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(3650)
                                ->default(60)
                                ->suffix('days'),
                            TextInput::make('pii_retention_days')
                                ->label('PII Retention (default)')
                                ->helperText('Days to keep recipient PII (name, address, phone, email) after shipping. Per-channel overrides can be set on each channel. Set to 0 to disable.')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(3650)
                                ->default(90)
                                ->suffix('days'),
                            Toggle::make('archiving_enabled')
                                ->label('Shipment Archiving')
                                ->helperText('When enabled, fully-shipped shipments older than the retention period are exported to CSV and removed from the database weekly. Historical stats are preserved.')
                                ->default(false)
                                ->live(),
                            TextInput::make('archive_retention_days')
                                ->label('Archive After')
                                ->helperText('Shipped shipments older than this will be archived. Archives are saved to storage/app/archives/.')
                                ->numeric()
                                ->minValue(90)
                                ->maxValue(3650)
                                ->default(365)
                                ->suffix('days')
                                ->visible(fn (Get $get): bool => (bool) $get('archiving_enabled')),
                        ])
                        ->columns(2),

                    Section::make('Testing')
                        ->description('Sandbox and testing settings')
                        ->schema([
                            Toggle::make('sandbox_mode')
                                ->label('Sandbox Mode')
                                ->helperText('When enabled, USPS, FedEx, and UPS API calls use sandbox/test URLs instead of production.')
                                ->default(false)
                                ->live(),
                            Toggle::make('suppress_printing')
                                ->label('Suppress Printing')
                                ->helperText('When enabled, label printing is skipped after shipping. Only available in sandbox mode.')
                                ->default(false)
                                ->visible(fn (Get $get): bool => (bool) $get('sandbox_mode')),
                        ])
                        ->columns(1),
                ])
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label('Save Settings')
                                ->submit('save')
                                ->keyBindings(['mod+s']),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $sandboxMode = (bool) ($data['sandbox_mode'] ?? false);
        $suppressPrinting = $sandboxMode ? (bool) ($data['suppress_printing'] ?? false) : false;
        $previousSandboxMode = (bool) app(SettingsService::class)->get('sandbox_mode', false);

        // Map form fields to setting keys
        $settings = [
            'company_name' => $data['company_name'] ?? '',
            'pack_slip_logo' => $data['pack_slip_logo'] ?? null,
            'packing_validation_enabled' => $data['packing_validation_enabled'] ?? true,
            'transparency_enabled' => $data['transparency_enabled'] ?? true,
            'batch_shipping_enabled' => $data['batch_shipping_enabled'] ?? true,
            'manual_shipping_enabled' => $data['manual_shipping_enabled'] ?? true,
            'picking_enabled' => (bool) ($data['picking_enabled'] ?? false),
            'multi_location_enabled' => (bool) ($data['multi_location_enabled'] ?? false),
            'multi_client_enabled' => (bool) ($data['multi_client_enabled'] ?? false),
            'carrier_api_timeout' => (int) ($data['carrier_api_timeout'] ?? 15),
            'audit_log_retention_days' => (int) ($data['audit_log_retention_days'] ?? 365),
            'rate_quote_retention_days' => (int) ($data['rate_quote_retention_days'] ?? 60),
            'pii_retention_days' => (int) ($data['pii_retention_days'] ?? 90),
            'archiving_enabled' => (bool) ($data['archiving_enabled'] ?? false),
            'archive_retention_days' => (int) ($data['archive_retention_days'] ?? 365),
            'password_min_length' => (int) ($data['password_min_length'] ?? 8),
            'password_require_mixed_case' => (bool) ($data['password_require_mixed_case'] ?? true),
            'password_require_numbers' => (bool) ($data['password_require_numbers'] ?? true),
            'password_require_symbols' => (bool) ($data['password_require_symbols'] ?? false),
            'password_expiration_days' => (int) ($data['password_expiration_days'] ?? 0),
            'google_sso_enabled' => (bool) ($data['google_sso_enabled'] ?? false),
            'sandbox_mode' => $sandboxMode,
            'suppress_printing' => $suppressPrinting,
        ];

        // Update each standard setting
        foreach ($settings as $key => $value) {
            $setting = Setting::find($key);

            if ($setting) {
                $setting->value = $value;
                $setting->save();
            } else {
                $type = is_bool($value) ? 'boolean' : (is_int($value) ? 'integer' : 'string');
                $group = str_contains($key, '.') ? explode('.', $key)[0] : 'general';

                Setting::create([
                    'key' => $key,
                    'value' => is_bool($value) ? ($value ? '1' : '0') : $value,
                    'type' => $type,
                    'group' => $group,
                ]);
            }
        }

        app(SettingsService::class)->clearCache();

        // Clear cached carrier auth tokens when sandbox mode changes
        if ($sandboxMode !== $previousSandboxMode) {
            Cache::forget('usps_authenticator');
            // Only the global fallback token (no CarrierAccount) is derived from Settings.
            // Per-account tokens are invalidated by CarrierAccount::clearTokenCaches() instead.
            Cache::forget('usps_payment_authorization_token:global');
            Cache::forget('fedex_authenticator');
            Cache::forget('fedex_authenticator_sandbox');
            Cache::forget('ups_authenticator');
            Cache::forget('ups_oauth_token');
        }

        $this->rememberData();

        Notification::make()
            ->success()
            ->title('Settings saved')
            ->send();
    }

    public function testUspsConnection(): void
    {
        $authMode = app(SettingsService::class)->get('usps.auth_mode', 'client_credentials');

        // Clear cached tokens so we test fresh from stored credentials
        Cache::forget('usps_authenticator');
        Cache::forget('usps_oauth_token');

        // For client credentials, verify we can obtain a token before testing rates
        if ($authMode !== 'authorization_code') {
            try {
                $connector = new USPSConnector;
                $connector->getAccessToken();
            } catch (\Throwable $e) {
                Notification::make()
                    ->danger()
                    ->title('USPS authentication failed')
                    ->body($e->getMessage())
                    ->send();

                return;
            }
        }

        // Test with the connector that will actually be used — auth mode aware
        try {
            $connector = USPSConnector::getAuthenticatedConnector();

            $request = new ShippingOptions;
            $request->body()->set([
                'pricingOptions' => [['priceType' => 'CONTRACT', 'paymentAccount' => ['accountType' => 'EPS', 'accountNumber' => app(SettingsService::class)->get('usps.eps_account', app(SettingsService::class)->get('usps.crid'))]]],
                'originZIPCode' => '90210',
                'destinationZIPCode' => '10001',
                'packageDescription' => ['weight' => 1.0, 'length' => 10, 'width' => 8, 'height' => 4, 'mailClass' => 'ALL_OUTBOUND', 'mailingDate' => date('Y-m-d')],
            ]);

            $connector->send($request);

            Cache::put('usps_pricing_type', 'CONTRACT', now()->addDays(7));

            Notification::make()
                ->success()
                ->title('USPS connected — CONTRACT pricing')
                ->body('Negotiated rates are available for this account.')
                ->send();
        } catch (ForbiddenException) {
            Cache::put('usps_pricing_type', 'RETAIL', now()->addDays(7));

            Notification::make()
                ->warning()
                ->title('USPS connected — RETAIL pricing')
                ->body('Authentication succeeded but this account does not have EPS contract access. Standard retail rates will be used. Contact USPS to enable negotiated rates.')
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->warning()
                ->title('USPS authenticated — rate test inconclusive')
                ->body('Credentials are valid but the rate check failed: '.$e->getMessage())
                ->send();
        }
    }
}
