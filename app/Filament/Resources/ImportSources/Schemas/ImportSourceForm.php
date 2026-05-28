<?php

namespace App\Filament\Resources\ImportSources\Schemas;

use App\Models\Client;
use App\Models\ImportSource;
use App\Services\OAuthService;
use App\Services\ShipmentImport\Sources\AmazonSource;
use App\Services\ShipmentImport\Sources\DatabaseSource;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Carbon\Carbon;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class ImportSourceForm
{
    private const DRIVERS = [
        DatabaseSource::class => 'Database (SQL)',
        ShopifySource::class => 'Shopify',
        AmazonSource::class => 'Amazon SP-API',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('General')
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                            if (filled($get('config_key'))) {
                                return;
                            }
                            $set('config_key', Str::slug($state ?? '', '_'));
                        }),

                    TextInput::make('config_key')
                        ->label('Config Key')
                        ->required()
                        ->maxLength(100)
                        ->helperText('Unique identifier used in logs and Artisan commands. Lowercase, underscores only.')
                        ->rules(['regex:/^[a-z0-9_]+$/'])
                        ->readOnly(fn (?ImportSource $record) => $record?->exists),

                    Select::make('client_id')
                        ->label('Client')
                        ->options(fn () => Client::orderBy('name')->pluck('name', 'id'))
                        ->nullable()
                        ->searchable()
                        ->helperText('Leave blank to share this source across all clients (single-tenant mode).'),

                    Select::make('driver')
                        ->label('Driver')
                        ->options(self::DRIVERS)
                        ->required()
                        ->live()
                        ->disabled(fn (?ImportSource $record) => $record?->exists)
                        ->dehydrated(),

                    Toggle::make('active')
                        ->default(true),
                ])
                ->columns(2),

            // ── Shopify ────────────────────────────────────────────────────────────

            Section::make('Shopify Connection')
                ->schema([
                    Placeholder::make('shopify_oauth_status')
                        ->label('OAuth Status')
                        ->content(fn (?ImportSource $record): HtmlString => self::renderShopifyOAuthStatus($record))
                        ->visible(fn (?ImportSource $record): bool => (bool) $record?->exists)
                        ->columnSpanFull(),

                    TextInput::make('settings.shop_domain')
                        ->label('Shop Domain')
                        ->placeholder('your-store.myshopify.com')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('settings.access_token')
                        ->label('Custom Access Token')
                        ->password()
                        ->placeholder(fn (?ImportSource $record) => filled($record?->secret('access_token') ?? $record?->settings['access_token'] ?? null) ? 'Configured (leave empty to keep)' : 'Not configured')
                        ->afterStateHydrated(fn ($component) => $component->state(null))
                        ->dehydrated(fn ($state) => filled($state))
                        ->helperText('Permanent offline token from a custom app. Leave blank to use OAuth (below) or client credentials.'),

                    TextInput::make('settings.client_id')
                        ->label('App Client ID')
                        ->password()
                        ->placeholder(fn (?ImportSource $record) => filled($record?->secret('client_id') ?? $record?->settings['client_id'] ?? null) ? 'Configured (leave empty to keep)' : 'Uses tenant-level credentials')
                        ->afterStateHydrated(fn ($component) => $component->state(null))
                        ->dehydrated(fn ($state) => filled($state))
                        ->helperText('Override the tenant-level Shopify app Client ID for this store.'),

                    TextInput::make('settings.client_secret')
                        ->label('App Client Secret')
                        ->password()
                        ->placeholder(fn (?ImportSource $record) => filled($record?->secret('client_secret') ?? $record?->settings['client_secret'] ?? null) ? 'Configured (leave empty to keep)' : 'Uses tenant-level credentials')
                        ->afterStateHydrated(fn ($component) => $component->state(null))
                        ->dehydrated(fn ($state) => filled($state))
                        ->helperText('Override the tenant-level Shopify app Client Secret for this store.'),
                ])
                ->visible(fn (Get $get): bool => $get('driver') === ShopifySource::class)
                ->columns(2),

            Section::make('Shopify Import Settings')
                ->schema([
                    TextInput::make('settings.channel_name')
                        ->label('Channel Name')
                        ->required()
                        ->default('Shopify')
                        ->maxLength(255)
                        ->helperText('Channel label assigned to imported shipments.'),

                    TextInput::make('settings.shipping_method')
                        ->label('Default Shipping Method')
                        ->nullable()
                        ->maxLength(255)
                        ->helperText('Leave blank to map per-order via channel aliases.'),

                    Toggle::make('settings.notify_customer')
                        ->label('Notify Customer on Fulfillment')
                        ->default(false),

                    Toggle::make('settings.export_enabled')
                        ->label('Write Fulfillment Back to Shopify')
                        ->default(false),
                ])
                ->visible(fn (Get $get): bool => $get('driver') === ShopifySource::class)
                ->columns(2),

            // ── Amazon ─────────────────────────────────────────────────────────────

            Section::make('Amazon SP-API Connection')
                ->schema([
                    TextInput::make('settings.marketplace_id')
                        ->label('Marketplace ID')
                        ->placeholder('ATVPDKIKX0DER')
                        ->required()
                        ->maxLength(50),

                    TextInput::make('settings.refresh_token')
                        ->label('Refresh Token')
                        ->password()
                        ->placeholder(fn (?ImportSource $record) => filled($record?->secret('refresh_token') ?? $record?->settings['refresh_token'] ?? null) ? 'Configured (leave empty to keep)' : 'Not configured')
                        ->afterStateHydrated(fn ($component) => $component->state(null))
                        ->dehydrated(fn ($state) => filled($state))
                        ->required(fn (?ImportSource $record) => ! $record?->exists)
                        ->helperText('LWA refresh token for this seller account.'),

                    TextInput::make('settings.client_id')
                        ->label('App Client ID')
                        ->password()
                        ->placeholder(fn (?ImportSource $record) => filled($record?->secret('client_id') ?? $record?->settings['client_id'] ?? null) ? 'Configured (leave empty to keep)' : 'Uses tenant-level credentials')
                        ->afterStateHydrated(fn ($component) => $component->state(null))
                        ->dehydrated(fn ($state) => filled($state))
                        ->helperText('Override the tenant-level SP-API app Client ID for this seller account.'),

                    TextInput::make('settings.client_secret')
                        ->label('App Client Secret')
                        ->password()
                        ->placeholder(fn (?ImportSource $record) => filled($record?->secret('client_secret') ?? $record?->settings['client_secret'] ?? null) ? 'Configured (leave empty to keep)' : 'Uses tenant-level credentials')
                        ->afterStateHydrated(fn ($component) => $component->state(null))
                        ->dehydrated(fn ($state) => filled($state))
                        ->helperText('Override the tenant-level SP-API app Client Secret for this seller account.'),
                ])
                ->visible(fn (Get $get): bool => $get('driver') === AmazonSource::class)
                ->columns(2),

            Section::make('Amazon Import Settings')
                ->schema([
                    TextInput::make('settings.channel_name')
                        ->label('Channel Name')
                        ->required()
                        ->default('Amazon')
                        ->maxLength(255),

                    TextInput::make('settings.shipping_method')
                        ->label('Default Shipping Method')
                        ->nullable()
                        ->maxLength(255),

                    TextInput::make('settings.lookback_days')
                        ->label('Lookback Days')
                        ->numeric()
                        ->default(30)
                        ->minValue(1)
                        ->maxValue(365),

                    Toggle::make('settings.export_enabled')
                        ->label('Confirm Shipment Back to Amazon')
                        ->default(false),
                ])
                ->visible(fn (Get $get): bool => $get('driver') === AmazonSource::class)
                ->columns(2),

            // ── Database ───────────────────────────────────────────────────────────

            Section::make('Database Connection')
                ->schema([
                    Select::make('settings.db_driver')
                        ->label('Driver')
                        ->options([
                            'mysql' => 'MySQL / MariaDB',
                            'pgsql' => 'PostgreSQL',
                            'sqlsrv' => 'SQL Server',
                            'sqlite' => 'SQLite',
                        ])
                        ->default('mysql')
                        ->required(),

                    TextInput::make('settings.db_host')
                        ->label('Host')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('settings.db_port')
                        ->label('Port')
                        ->numeric()
                        ->default(3306),

                    TextInput::make('settings.db_database')
                        ->label('Database')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('settings.db_username')
                        ->label('Username')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('settings.db_password')
                        ->label('Password')
                        ->password()
                        ->placeholder(fn (?ImportSource $record) => filled($record?->secret('db_password') ?? $record?->settings['db_password'] ?? null) ? 'Configured (leave empty to keep)' : 'Not configured')
                        ->afterStateHydrated(fn ($component) => $component->state(null))
                        ->dehydrated(fn ($state) => filled($state)),
                ])
                ->visible(fn (Get $get): bool => $get('driver') === DatabaseSource::class)
                ->columns(2),

            Section::make('Database Query')
                ->schema([
                    TextInput::make('settings.shipments_table')
                        ->label('Shipments Table')
                        ->default('shipments')
                        ->maxLength(255),

                    TextInput::make('settings.shipment_items_table')
                        ->label('Items Table')
                        ->default('shipment_items')
                        ->maxLength(255),

                    TextInput::make('settings.client_column')
                        ->label('Client Column')
                        ->nullable()
                        ->maxLength(255)
                        ->helperText('Column in each row that identifies the client (matched by name). When set, the Client field above is ignored and each row maps to its own client.')
                        ->columnSpanFull(),

                    Textarea::make('settings.shipments_query')
                        ->label('Custom Shipments Query')
                        ->nullable()
                        ->rows(3)
                        ->helperText('Optional. Overrides table + filters. Leave blank to use table-based query.')
                        ->columnSpanFull(),

                    Textarea::make('settings.shipment_items_query')
                        ->label('Custom Items Query')
                        ->nullable()
                        ->rows(3)
                        ->helperText('Use :shipment_reference as the placeholder. Leave blank to query by shipment_id.')
                        ->columnSpanFull(),

                    Toggle::make('settings.mark_exported_enabled')
                        ->label('Mark Exported After Import'),

                    Textarea::make('settings.mark_exported_query')
                        ->label('Mark Exported Query')
                        ->nullable()
                        ->rows(2)
                        ->helperText('Use :shipment_reference as placeholder.')
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => (bool) $get('settings.mark_exported_enabled')),
                ])
                ->visible(fn (Get $get): bool => $get('driver') === DatabaseSource::class)
                ->columns(2),

            Section::make('Database Export')
                ->schema([
                    Toggle::make('settings.export_enabled')
                        ->label('Write Tracking Back to Source Database')
                        ->live()
                        ->default(false),

                    Textarea::make('settings.export_query')
                        ->label('Export Query')
                        ->nullable()
                        ->rows(3)
                        ->helperText('Available parameters: :tracking_number, :carrier, :service, :weight, :cost, :shipment_reference')
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => (bool) $get('settings.export_enabled')),
                ])
                ->visible(fn (Get $get): bool => $get('driver') === DatabaseSource::class)
                ->columns(2)
                ->collapsible()
                ->collapsed(),

            Section::make('SSH Tunnel')
                ->schema([
                    Toggle::make('settings.ssh_enabled')
                        ->label('Enable SSH Tunnel')
                        ->live()
                        ->default(false),

                    TextInput::make('settings.ssh_host')
                        ->label('SSH Host')
                        ->maxLength(255)
                        ->visible(fn (Get $get): bool => (bool) $get('settings.ssh_enabled')),

                    TextInput::make('settings.ssh_port')
                        ->label('SSH Port')
                        ->numeric()
                        ->default(22)
                        ->visible(fn (Get $get): bool => (bool) $get('settings.ssh_enabled')),

                    TextInput::make('settings.ssh_user')
                        ->label('SSH User')
                        ->maxLength(255)
                        ->visible(fn (Get $get): bool => (bool) $get('settings.ssh_enabled')),

                    TextInput::make('settings.ssh_remote_host')
                        ->label('Remote DB Host')
                        ->maxLength(255)
                        ->helperText('Override if DB runs on a different host than the SSH server.')
                        ->visible(fn (Get $get): bool => (bool) $get('settings.ssh_enabled')),

                    TextInput::make('settings.ssh_remote_port')
                        ->label('Remote DB Port')
                        ->numeric()
                        ->visible(fn (Get $get): bool => (bool) $get('settings.ssh_enabled')),

                    Textarea::make('settings.ssh_host_key')
                        ->label('SSH Host Key')
                        ->nullable()
                        ->rows(2)
                        ->helperText('Known-hosts entry for this server. Paste the line from ssh-keyscan output.')
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => (bool) $get('settings.ssh_enabled')),
                ])
                ->visible(fn (Get $get): bool => $get('driver') === DatabaseSource::class)
                ->columns(2)
                ->collapsible()
                ->collapsed(),
        ]);
    }

    private static function renderShopifyOAuthStatus(?ImportSource $record): HtmlString
    {
        if (! $record?->exists) {
            return new HtmlString('');
        }

        $connected = app(OAuthService::class)->isImportSourceConnected($record);
        $connectedAt = $record->settings['oauth_connected_at'] ?? null;
        $scopes = $record->settings['oauth_scopes'] ?? null;

        return new HtmlString(
            view('filament.pages.settings.oauth-status', [
                'connected' => $connected,
                'time' => $connectedAt ? Carbon::parse($connectedAt)->diffForHumans() : null,
                'scopes' => $scopes,
            ])->render()
        );
    }
}
