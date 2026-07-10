<?php

use App\Models\CarrierAccount;
use App\Models\DataSource;

it('does not serialize carrier account credentials', function (): void {
    $account = CarrierAccount::factory()->create([
        'secret_credentials' => ['client_secret' => 'carrier-account-secret'],
    ]);

    $this->assertArrayNotHasKey('secret_credentials', $account->toArray());
    $this->assertNotContains('carrier-account-secret', $account->toArray());
});

it('does not serialize data source secrets', function (): void {
    $dataSource = DataSource::factory()->create([
        'secret_settings' => ['db_password' => 'data-source-secret'],
    ]);

    $this->assertArrayNotHasKey('secret_settings', $dataSource->toArray());
    $this->assertNotContains('data-source-secret', $dataSource->toArray());
});
