<?php

use App\Enums\Role;
use App\Filament\Resources\DataSources\DataSourceResource;
use App\Filament\Resources\DataSources\Pages\CreateDataSource;
use App\Filament\Resources\DataSources\Pages\EditDataSource;
use App\Filament\Resources\DataSources\Pages\ListDataSources;
use App\Jobs\RunDataSourceImportJob;
use App\Models\DataSource;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\ShipmentImport\Sources\DatabaseSource;
use App\Services\ShipmentImport\Sources\ShopifySource;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
});

// ── Access control ────────────────────────────────────────────────────────────

it('blocks non-admin users from accessing import sources', function (): void {
    $user = User::factory()->create(['role' => Role::User]);
    $this->actingAs($user);

    expect(DataSourceResource::canAccess())->toBeFalse();
});

it('allows admin users to access import sources', function (): void {
    $this->actingAs($this->admin);

    expect(DataSourceResource::canAccess())->toBeTrue();
});

// ── Single-client vs multi-client visibility ──────────────────────────────────

it('hides client and global export fields in single-client mode', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(CreateDataSource::class)
        ->fillForm([
            'driver' => DatabaseSource::class,
            'settings.export_enabled' => true,
        ])
        ->assertFormFieldHidden('client_id')
        ->assertFormFieldHidden('settings.client_column')
        ->assertFormFieldHidden('global_export');
});

it('shows client and global export fields in multi-client mode', function (): void {
    app(SettingsService::class)->set('multi_client_enabled', true, 'boolean');
    $this->actingAs($this->admin);

    Livewire::test(CreateDataSource::class)
        ->fillForm([
            'driver' => DatabaseSource::class,
            'settings.export_enabled' => true,
        ])
        ->assertFormFieldVisible('client_id')
        ->assertFormFieldVisible('settings.client_column')
        ->assertFormFieldVisible('global_export');
});

it('hides client and global export table columns in single-client mode', function (): void {
    $this->actingAs($this->admin);
    DataSource::factory()->create();

    Livewire::test(ListDataSources::class)
        ->assertTableColumnHidden('client.name')
        ->assertTableColumnHidden('global_export');
});

it('shows client and global export table columns in multi-client mode', function (): void {
    app(SettingsService::class)->set('multi_client_enabled', true, 'boolean');
    $this->actingAs($this->admin);
    DataSource::factory()->create();

    Livewire::test(ListDataSources::class)
        ->assertTableColumnVisible('client.name')
        ->assertTableColumnVisible('global_export');
});

// ── Manual import trigger ─────────────────────────────────────────────────────

it('dispatches an import job from the edit page header action', function (): void {
    Queue::fake();
    $this->actingAs($this->admin);

    $source = DataSource::factory()->create(['active' => true]);

    Livewire::test(EditDataSource::class, ['record' => $source->id])
        ->callAction('run_import')
        ->assertNotified('Import queued');

    Queue::assertPushed(RunDataSourceImportJob::class, function (RunDataSourceImportJob $job) use ($source): bool {
        return $job->dataSourceId === $source->id && $job->userId === $this->admin->id;
    });
});

it('disables the run import action for inactive sources', function (): void {
    $this->actingAs($this->admin);

    $source = DataSource::factory()->create(['active' => false]);

    Livewire::test(EditDataSource::class, ['record' => $source->id])
        ->assertActionDisabled('run_import');
});

it('dispatches an import job from the table row action', function (): void {
    Queue::fake();
    $this->actingAs($this->admin);

    $source = DataSource::factory()->create(['active' => true]);

    Livewire::test(ListDataSources::class)
        ->callAction(TestAction::make('run_import')->table($source))
        ->assertNotified('Import queued');

    Queue::assertPushed(RunDataSourceImportJob::class, fn (RunDataSourceImportJob $job): bool => $job->dataSourceId === $source->id);
});

it('hides the table run import action for inactive sources', function (): void {
    $this->actingAs($this->admin);

    $source = DataSource::factory()->create(['active' => false]);

    Livewire::test(ListDataSources::class)
        ->assertActionHidden(TestAction::make('run_import')->table($source));
});

// ── Database driver form behavior ─────────────────────────────────────────────

it('reveals the mark exported query field when the toggle is enabled', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(CreateDataSource::class)
        ->fillForm(['driver' => DatabaseSource::class])
        ->assertFormFieldHidden('settings.mark_exported_query')
        ->fillForm(['settings.mark_exported_enabled' => true])
        ->assertFormFieldVisible('settings.mark_exported_query');
});

it('shows the generated ssh public key when database ssh tunneling is enabled', function (): void {
    $pubKeyPath = storage_path('app/private/ssh/id_ed25519.pub');
    $existingPublicKey = File::exists($pubKeyPath) ? File::get($pubKeyPath) : null;

    try {
        File::ensureDirectoryExists(dirname($pubKeyPath));
        File::put($pubKeyPath, 'ssh-ed25519 AAAApolybagpublickey');

        $this->actingAs($this->admin);

        Livewire::test(CreateDataSource::class)
            ->fillForm([
                'driver' => DatabaseSource::class,
                'settings.ssh_enabled' => true,
            ])
            ->assertFormFieldVisible('ssh_public_key')
            ->assertSchemaStateSet([
                'ssh_public_key' => 'restrict,port-forwarding ssh-ed25519 AAAApolybagpublickey',
            ]);
    } finally {
        if ($existingPublicKey === null) {
            File::delete($pubKeyPath);
        } else {
            File::put($pubKeyPath, $existingPublicKey);
        }
    }
});

it('shows the generated ssh public key on the edit form when ssh tunneling is enabled', function (): void {
    $pubKeyPath = storage_path('app/private/ssh/id_ed25519.pub');
    $existingPublicKey = File::exists($pubKeyPath) ? File::get($pubKeyPath) : null;

    try {
        File::ensureDirectoryExists(dirname($pubKeyPath));
        File::put($pubKeyPath, 'ssh-ed25519 AAAApolybagpublickey');

        $this->actingAs($this->admin);

        $source = DataSource::factory()->create([
            'settings' => ['ssh_enabled' => true],
        ]);

        Livewire::test(EditDataSource::class, ['record' => $source->id])
            ->assertFormFieldVisible('ssh_public_key')
            ->assertSchemaStateSet([
                'ssh_public_key' => 'restrict,port-forwarding ssh-ed25519 AAAApolybagpublickey',
            ]);
    } finally {
        if ($existingPublicKey === null) {
            File::delete($pubKeyPath);
        } else {
            File::put($pubKeyPath, $existingPublicKey);
        }
    }
});

it('can test the database connection before the source is created', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(CreateDataSource::class)
        ->fillForm([
            'name' => 'New DB Source',
            'driver' => DatabaseSource::class,
            'settings.db_driver' => 'sqlite',
            'settings.db_database' => ':memory:',
        ])
        ->callAction(TestAction::make('test_db_connection')->schemaComponent('database_connection'))
        ->assertNotified('Connection successful');
});

it('reports a failed connection test before the source is created', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(CreateDataSource::class)
        ->fillForm([
            'name' => 'New DB Source',
            'driver' => DatabaseSource::class,
            'settings.db_driver' => 'sqlite',
            'settings.db_database' => '/nonexistent/path/db.sqlite',
        ])
        ->callAction(TestAction::make('test_db_connection')->schemaComponent('database_connection'))
        ->assertNotified('Connection failed');
});

// ── Secret settings encryption ────────────────────────────────────────────────

it('routes secret keys to encrypted secret_settings on create', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(CreateDataSource::class)
        ->fillForm([
            'name' => 'Shopify Test',
            'driver' => ShopifySource::class,
            'settings.shop_domain' => 'test.myshopify.com',
            'settings.access_token' => 'shpat_secret_token',
            'settings.client_id' => 'secret_client_id',
            'settings.client_secret' => 'secret_client_secret',
            'settings.channel_name' => 'Shopify',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $record = DataSource::where('name', 'Shopify Test')->firstOrFail();

    // Secrets must be in encrypted column, not plain settings
    expect($record->settings)->not->toHaveKey('access_token');
    expect($record->settings)->not->toHaveKey('client_id');
    expect($record->settings)->not->toHaveKey('client_secret');

    expect($record->secret('access_token'))->toBe('shpat_secret_token');
    expect($record->secret('client_id'))->toBe('secret_client_id');
    expect($record->secret('client_secret'))->toBe('secret_client_secret');
});

it('routes db_password to secret_settings on create', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(CreateDataSource::class)
        ->fillForm([
            'name' => 'DB Source',
            'driver' => DatabaseSource::class,
            'settings.db_host' => 'localhost',
            'settings.db_database' => 'orders',
            'settings.db_username' => 'reader',
            'settings.db_password' => 'supersecret',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $record = DataSource::where('name', 'DB Source')->firstOrFail();

    expect($record->settings)->not->toHaveKey('db_password');
    expect($record->secret('db_password'))->toBe('supersecret');
});

it('preserves existing secrets when a blank password is submitted on edit', function (): void {
    $this->actingAs($this->admin);

    $source = DataSource::factory()->shopify()->create([
        'secret_settings' => ['access_token' => 'original_token'],
    ]);

    Livewire::test(EditDataSource::class, ['record' => $source->id])
        ->fillForm([
            'name' => 'Updated Name',
            'settings.shop_domain' => 'test.myshopify.com',
            'settings.access_token' => null,
            'settings.channel_name' => 'Shopify',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $source->refresh();
    expect($source->secret('access_token'))->toBe('original_token');
});

it('replaces a secret when a new value is submitted on edit', function (): void {
    $this->actingAs($this->admin);

    $source = DataSource::factory()->shopify()->create([
        'secret_settings' => ['access_token' => 'old_token'],
    ]);

    Livewire::test(EditDataSource::class, ['record' => $source->id])
        ->fillForm([
            'name' => 'Updated Name',
            'settings.shop_domain' => 'test.myshopify.com',
            'settings.access_token' => 'new_token',
            'settings.channel_name' => 'Shopify',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $source->refresh();
    expect($source->secret('access_token'))->toBe('new_token');
});

it('migrates legacy plaintext secrets from settings to secret_settings on edit', function (): void {
    $this->actingAs($this->admin);

    // Simulate a record saved before the encrypted column was introduced
    $source = DataSource::factory()->shopify()->create([
        'settings' => [
            'shop_domain' => 'test.myshopify.com',
            'channel_name' => 'Shopify',
            'access_token' => 'legacy_plaintext_token',
        ],
    ]);

    Livewire::test(EditDataSource::class, ['record' => $source->id])
        ->fillForm([
            'name' => 'Legacy Source',
            'settings.shop_domain' => 'test.myshopify.com',
            'settings.access_token' => null,
            'settings.channel_name' => 'Shopify',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $source->refresh();
    expect($source->settings)->not->toHaveKey('access_token');
    expect($source->secret('access_token'))->toBe('legacy_plaintext_token');
});

// ── ShopifySource validation ──────────────────────────────────────────────────

it('validates when oauth_access_token is present even without tenant credentials', function (): void {
    $source = new ShopifySource([
        'shop_domain' => 'test.myshopify.com',
        'oauth_access_token' => 'shpat_oauth_token',
        'channel_name' => 'Shopify',
    ]);

    // Should not throw — oauth token satisfies the credentials requirement
    $source->validateConfiguration();
    expect(true)->toBeTrue();
});

it('validates when per-source client_id and client_secret are both present', function (): void {
    $source = new ShopifySource([
        'shop_domain' => 'test.myshopify.com',
        'client_id' => 'per_source_id',
        'client_secret' => 'per_source_secret',
        'channel_name' => 'Shopify',
    ]);

    $source->validateConfiguration();
    expect(true)->toBeTrue();
});

it('fails validation when neither token nor credentials exist and no tenant credentials', function (): void {
    // Clear the global Shopify settings seeded in Pest.php beforeEach
    Setting::where('group', 'shopify')->delete();
    app(SettingsService::class)->clearCache();

    $source = new ShopifySource([
        'shop_domain' => 'test.myshopify.com',
        'channel_name' => 'Shopify',
    ]);

    expect(fn () => $source->validateConfiguration())->toThrow(InvalidArgumentException::class, 'client ID');
});
