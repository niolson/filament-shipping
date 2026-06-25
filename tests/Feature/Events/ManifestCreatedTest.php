<?php

use App\Enums\PackageStatus;
use App\Events\ManifestCreated;
use App\Http\Integrations\USPS\Requests\ScanForm;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\Location;
use App\Models\Package;
use App\Services\ManifestService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

it('dispatches ManifestCreated after successful USPS manifest creation', function (): void {
    Event::fake([ManifestCreated::class]);
    $account = createUspsAccount();

    $package = Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'carrier_account_id' => $account->id,
        'tracking_number' => '9400111899223456789012',
    ]);

    $boundary = 'test-boundary';
    $jsonPart = json_encode(['manifestNumber' => 'MN12345', 'trackingNumbers' => ['9400111899223456789012']]);
    $pdfPart = base64_encode('fake-manifest-pdf');

    $multipartBody = "--{$boundary}\r\n"
        ."Content-Type: application/json\r\n"
        ."\r\n"
        ."{$jsonPart}\r\n"
        ."--{$boundary}\r\n"
        ."Content-Type: application/pdf\r\n"
        ."\r\n"
        ."{$pdfPart}\r\n"
        ."--{$boundary}--";

    Cache::put("usps_authenticator:{$account->id}", [
        'access_token' => 'fake-test-token',
        'refresh_token' => null,
        'expires_at' => (new DateTimeImmutable('+1 hour'))->getTimestamp(),
    ], 3600);

    Saloon::fake([
        ScanForm::class => MockResponse::make(
            body: $multipartBody,
            status: 200,
            headers: ['Content-Type' => "multipart/mixed; boundary={$boundary}"],
        ),
    ]);

    // Need a default location for scan form from-address
    Location::factory()->default()->create([
        'first_name' => 'Test',
        'last_name' => 'Shipper',
        'address1' => '123 Main St',
        'city' => 'Anytown',
        'state_or_province' => 'NY',
        'postal_code' => '10001',
    ]);

    $packages = Package::where('carrier', 'USPS')
        ->where('status', PackageStatus::Shipped)
        ->whereNull('manifest_id')
        ->get();

    $result = app(ManifestService::class)->createManifest('USPS', $packages);

    expect($result->success)->toBeTrue();

    Event::assertDispatched(ManifestCreated::class, function (ManifestCreated $event): bool {
        return $event->packageCount === 1
            && $event->manifest->carrier === 'USPS';
    });
});

it('creates separate USPS manifests for packages purchased with different carrier accounts', function (): void {
    Event::fake([ManifestCreated::class]);

    $carrier = Carrier::factory()->usps()->create();
    $firstAccount = CarrierAccount::factory()->create([
        'carrier_id' => $carrier->id,
        'secret_credentials' => ['client_id' => 'first-client', 'client_secret' => 'first-secret'],
        'credentials' => ['crid' => 'first-crid'],
    ]);
    $secondAccount = CarrierAccount::factory()->create([
        'carrier_id' => $carrier->id,
        'secret_credentials' => ['client_id' => 'second-client', 'client_secret' => 'second-secret'],
        'credentials' => ['crid' => 'second-crid'],
    ]);

    $firstPackage = Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'carrier_account_id' => $firstAccount->id,
        'tracking_number' => '9400111899223456789011',
    ]);
    $secondPackage = Package::factory()->shipped()->create([
        'carrier' => 'USPS',
        'carrier_account_id' => $secondAccount->id,
        'tracking_number' => '9400111899223456789012',
    ]);

    $multipartResponse = function (string $manifestNumber, string $trackingNumber): MockResponse {
        $boundary = "boundary-{$manifestNumber}";
        $jsonPart = json_encode(['manifestNumber' => $manifestNumber, 'trackingNumbers' => [$trackingNumber]]);
        $pdfPart = base64_encode("manifest-{$manifestNumber}");
        $body = "--{$boundary}\r\n"
            ."Content-Type: application/json\r\n\r\n{$jsonPart}\r\n"
            ."--{$boundary}\r\n"
            ."Content-Type: application/pdf\r\n\r\n{$pdfPart}\r\n"
            ."--{$boundary}--";

        return MockResponse::make(
            body: $body,
            status: 200,
            headers: ['Content-Type' => "multipart/mixed; boundary={$boundary}"],
        );
    };

    $scanFormCall = 0;

    Saloon::fake([
        '*oauth*' => MockResponse::make(['access_token' => 'test-token', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        ScanForm::class => function () use (&$scanFormCall, $multipartResponse, $firstPackage, $secondPackage): MockResponse {
            $scanFormCall++;

            return $scanFormCall === 1
                ? $multipartResponse('MN-FIRST', $firstPackage->tracking_number)
                : $multipartResponse('MN-SECOND', $secondPackage->tracking_number);
        },
    ]);

    Location::factory()->default()->create([
        'first_name' => 'Test',
        'last_name' => 'Shipper',
        'address1' => '123 Main St',
        'city' => 'Anytown',
        'state_or_province' => 'NY',
        'postal_code' => '10001',
    ]);

    $result = app(ManifestService::class)->createManifest('USPS', collect([$firstPackage, $secondPackage]));

    expect($result->success)->toBeTrue()
        ->and($result->manifestNumber)->toContain('MN-FIRST')
        ->and($result->manifestNumber)->toContain('MN-SECOND')
        ->and($firstPackage->fresh()->manifest_id)->not->toBeNull()
        ->and($secondPackage->fresh()->manifest_id)->not->toBeNull()
        ->and($firstPackage->fresh()->manifest_id)->not->toBe($secondPackage->fresh()->manifest_id);

    Event::assertDispatchedTimes(ManifestCreated::class, 2);
});
