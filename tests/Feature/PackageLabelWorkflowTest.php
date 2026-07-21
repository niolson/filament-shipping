<?php

use App\Contracts\CarrierAdapterInterface;
use App\Contracts\PackageLabelWorkflow;
use App\DataTransferObjects\Shipping\CancelResponse;
use App\Enums\PackageStatus;
use App\Enums\Role;
use App\Models\Package;
use App\Models\User;
use App\Services\Carriers\CarrierRegistry;
use Saloon\Exceptions\Request\Statuses\RequestTimeOutException;
use Saloon\Http\Response;

beforeEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

afterEach(function (): void {
    app(CarrierRegistry::class)->reset();
});

it('voids a shipped package label and clears shipping data', function (): void {
    $package = Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'tracking_number' => '9400111899223456789012',
        'label_data' => 'base64-label',
    ]);

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('cancelShipment')
        ->once()
        ->with('9400111899223456789012', Mockery::on(fn (Package $argument): bool => $argument->is($package)))
        ->andReturn(CancelResponse::success('Label voided successfully.'));

    app(CarrierRegistry::class)->registerInstance('USPS', $adapter);

    $result = app(PackageLabelWorkflow::class)->voidLabel($package);

    expect($result->success)->toBeTrue()
        ->and($result->message)->toBe('Label voided successfully.')
        ->and($package->fresh()->status)->toBe(PackageStatus::Unshipped)
        ->and($package->fresh()->tracking_number)->toBeNull()
        ->and($package->fresh()->label_data)->toBeNull();
});

it('returns a failure result when carrier label voiding fails', function (): void {
    $package = Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'tracking_number' => '9400111899223456789012',
    ]);

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('cancelShipment')
        ->once()
        ->andReturn(CancelResponse::failure('Already voided.'));

    app(CarrierRegistry::class)->registerInstance('USPS', $adapter);

    $result = app(PackageLabelWorkflow::class)->voidLabel($package);

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Void failed')
        ->and($package->fresh()->status)->toBe(PackageStatus::Shipped);
});

it('refuses to void a package that is not shipped', function (): void {
    $package = Package::factory()->create([
        'status' => PackageStatus::Unshipped,
        'carrier' => 'USPS',
        'tracking_number' => '9400111899223456789012',
    ]);

    $result = app(PackageLabelWorkflow::class)->voidLabel($package);

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Package Not Found');
});

it('refuses to void a shipped package missing tracking information', function (): void {
    $package = Package::factory()->shipped()->create([
        'carrier' => null,
        'tracking_number' => null,
    ]);

    $result = app(PackageLabelWorkflow::class)->voidLabel($package);

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Cannot Cancel');
});

it('reports a state change when voiding hits a runtime exception', function (): void {
    $package = Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'tracking_number' => '9400111899223456789012',
    ]);

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('cancelShipment')
        ->once()
        ->andThrow(new RuntimeException('Package was modified by another user.'));

    app(CarrierRegistry::class)->registerInstance('USPS', $adapter);

    $result = app(PackageLabelWorkflow::class)->voidLabel($package);

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Package State Changed')
        ->and($result->message)->toBe('Package was modified by another user.');
});

it('reports a carrier error when voiding hits a request exception', function (): void {
    $package = Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'tracking_number' => '9400111899223456789012',
    ]);

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('cancelShipment')
        ->once()
        ->andThrow(new RequestTimeOutException(Mockery::mock(Response::class), 'timed out'));

    app(CarrierRegistry::class)->registerInstance('USPS', $adapter);

    $result = app(PackageLabelWorkflow::class)->voidLabel($package);

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Carrier Error');
});

it('reports a generic error when voiding hits an unexpected exception', function (): void {
    $package = Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'tracking_number' => '9400111899223456789012',
    ]);

    $adapter = Mockery::mock(CarrierAdapterInterface::class);
    $adapter->shouldReceive('cancelShipment')
        ->once()
        ->andThrow(new Exception('boom'));

    app(CarrierRegistry::class)->registerInstance('USPS', $adapter);

    $result = app(PackageLabelWorkflow::class)->voidLabel($package);

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Cancel Error');
});

it('refuses label reprint when the package is not shipped or has no label data', function (): void {
    $user = User::factory()->create(['role' => Role::User]);
    $package = Package::factory()->shipped()->create([
        'shipped_by_user_id' => $user->id,
        'label_data' => null,
    ]);

    $result = app(PackageLabelWorkflow::class)->labelForReprint($package, $user);

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Label Not Available');
});

it('allows a manager to reprint a label for a package shipped by someone else', function (): void {
    $shipper = User::factory()->create(['role' => Role::User]);
    $manager = User::factory()->create(['role' => Role::Manager]);
    $package = Package::factory()->shipped()->create([
        'shipped_by_user_id' => $shipper->id,
        'label_data' => 'base64-label',
        'tracking_number' => 'TRACK999',
    ]);

    $result = app(PackageLabelWorkflow::class)->labelForReprint($package, $manager);

    expect($result->success)->toBeTrue()
        ->and($result->message)->toBe('Reprinted label for tracking: TRACK999');
});

it('returns a print request for label reprint when the user can access the package', function (): void {
    $user = User::factory()->create(['role' => Role::User]);
    $package = Package::factory()->shipped()->create([
        'shipped_by_user_id' => $user->id,
        'tracking_number' => 'TRACK123',
        'label_data' => 'base64-label',
        'label_orientation' => 'landscape',
        'label_format' => 'zpl',
        'label_dpi' => 203,
    ]);

    $result = app(PackageLabelWorkflow::class)->labelForReprint($package, $user);

    expect($result->success)->toBeTrue()
        ->and($result->printRequest->label)->toBe('base64-label')
        ->and($result->printRequest->orientation)->toBe('landscape')
        ->and($result->printRequest->format)->toBe('zpl')
        ->and($result->printRequest->dpi)->toBe(203)
        ->and($result->message)->toBe('Reprinted label for tracking: TRACK123');
});

it('rejects label reprint for a different non-manager user', function (): void {
    $shipper = User::factory()->create(['role' => Role::User]);
    $otherUser = User::factory()->create(['role' => Role::User]);
    $package = Package::factory()->shipped()->create([
        'shipped_by_user_id' => $shipper->id,
        'label_data' => 'base64-label',
    ]);

    $result = app(PackageLabelWorkflow::class)->labelForReprint($package, $otherUser);

    expect($result->success)->toBeFalse()
        ->and($result->title)->toBe('Access Denied');
});
