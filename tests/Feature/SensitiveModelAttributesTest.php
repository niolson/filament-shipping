<?php

use App\Models\CarrierAccount;
use App\Models\DataSource;

it('does not serialize carrier account credentials', function (): void {
    $account = CarrierAccount::factory()->create([
        'secret_credentials' => ['client_secret' => 'carrier-account-secret'],
    ]);

    expect($account->toArray())
        ->not->toHaveKey('secret_credentials')
        ->not->toContain('carrier-account-secret');
});

it('does not serialize data source secrets', function (): void {
    $dataSource = DataSource::factory()->create([
        'secret_settings' => ['db_password' => 'data-source-secret'],
    ]);

    expect($dataSource->toArray())
        ->not->toHaveKey('secret_settings')
        ->not->toContain('data-source-secret');
});
