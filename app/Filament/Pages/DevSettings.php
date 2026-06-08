<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Models\Setting;
use App\Services\SettingsService;
use BackedEnum;
use Filament\Actions\Action;
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
use UnitEnum;

/**
 * @property-read Schema $form
 */
class DevSettings extends Page
{
    use HasUnsavedDataChangesAlert;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationLabel = 'Dev Settings';

    protected static UnitEnum|string|null $navigationGroup = 'Admin';

    protected static ?int $navigationSort = 100;

    protected string $view = 'filament.pages.dev-settings';

    public static function canAccess(): bool
    {
        return app()->environment('local', 'testing') && auth()->user()?->role->isAtLeast(Role::Admin);
    }

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $settings = app(SettingsService::class);

        $this->form->fill([
            'sandbox_mode' => $settings->get('sandbox_mode', false),
            'suppress_printing' => $settings->get('suppress_printing', false),
            'multi_client_enabled' => $settings->get('multi_client_enabled', false),
            'multi_location_enabled' => $settings->get('multi_location_enabled', false),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make('Carrier APIs')
                        ->description('Control which carrier API environment is used. These settings only apply in local development.')
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

                    Section::make('Mode Overrides')
                        ->description('Toggle multi-tenant features for local testing. In production these are controlled by MULTI_CLIENT_ENABLED and MULTI_LOCATION_ENABLED in .env.')
                        ->schema([
                            Toggle::make('multi_client_enabled')
                                ->label('Multi-Client (3PL)')
                                ->helperText('When enabled, shipments, products, and shipping rules are scoped per client. Client columns and selectors appear across the UI.')
                                ->default(false),
                            Toggle::make('multi_location_enabled')
                                ->label('Multi-Location')
                                ->helperText('When enabled, packages are tracked per warehouse and end-of-day processes run per location. Each user can be assigned a default location.')
                                ->default(false),
                        ])
                        ->columns(1),
                ])
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label('Save Dev Settings')
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

        $settings = [
            'sandbox_mode' => $sandboxMode,
            'suppress_printing' => $suppressPrinting,
            'multi_client_enabled' => (bool) ($data['multi_client_enabled'] ?? false),
            'multi_location_enabled' => (bool) ($data['multi_location_enabled'] ?? false),
        ];

        foreach ($settings as $key => $value) {
            $setting = Setting::find($key);

            if ($setting) {
                $setting->value = $value;
                $setting->save();
            } else {
                Setting::create([
                    'key' => $key,
                    'value' => $value ? '1' : '0',
                    'type' => 'boolean',
                    'group' => 'dev',
                ]);
            }
        }

        app(SettingsService::class)->clearCache();

        if ($sandboxMode !== $previousSandboxMode) {
            Cache::forget('usps_authenticator');
            Cache::forget('usps_payment_authorization_token:global');
            Cache::forget('fedex_authenticator');
            Cache::forget('fedex_authenticator_sandbox');
            Cache::forget('ups_authenticator');
            Cache::forget('ups_oauth_token');
        }

        $this->rememberData();

        Notification::make()
            ->success()
            ->title('Dev settings saved')
            ->send();
    }
}
