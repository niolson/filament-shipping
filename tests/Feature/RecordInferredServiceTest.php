<?php

use App\DataTransferObjects\Shipping\ServiceInference;
use App\Enums\ServiceEvidence;
use App\Models\Package;
use Illuminate\Support\Facades\DB;

function inference(string $service = 'USPS Ground Advantage', string $version = '2026-09-06'): ServiceInference
{
    return ServiceInference::resolved($service, 'usps-impb-stc', $version);
}

it('records an inferred service over an unknown one', function (): void {
    $package = Package::factory()->shipped()->create([
        'service' => null,
        'service_evidence' => ServiceEvidence::Unknown,
        'service_inference_method' => null,
        'service_ruleset_version' => null,
    ]);

    expect($package->recordInferredService(inference()))->toBeTrue();

    $package->refresh();

    expect($package->service)->toBe('USPS Ground Advantage')
        ->and($package->service_evidence)->toBe(ServiceEvidence::Inferred)
        ->and($package->service_inference_method)->toBe('usps-impb-stc')
        ->and($package->service_ruleset_version)->toBe('2026-09-06');
});

it('never writes an inferred service over a confirmed one', function (): void {
    $package = Package::factory()->shipped()->create([
        'service' => 'Priority Mail',
        'service_evidence' => ServiceEvidence::Confirmed,
    ]);

    expect($package->recordInferredService(inference()))->toBeFalse();

    $package->refresh();

    expect($package->service)->toBe('Priority Mail')
        ->and($package->service_evidence)->toBe(ServiceEvidence::Confirmed)
        ->and($package->service_inference_method)->toBeNull();
});

it('never downgrades an inferred service when a later run resolves nothing', function (): void {
    $package = Package::factory()->shipped()->create([
        'service' => 'USPS Ground Advantage',
        'service_evidence' => ServiceEvidence::Inferred,
        'service_inference_method' => 'usps-impb-stc',
        'service_ruleset_version' => '2026-09-06',
    ]);

    expect($package->recordInferredService(ServiceInference::inconclusive('nothing matched')))->toBeFalse();

    $package->refresh();

    expect($package->service)->toBe('USPS Ground Advantage')
        ->and($package->service_evidence)->toBe(ServiceEvidence::Inferred);
});

it('replaces an inferred value and its version stamp together under a newer ruleset', function (): void {
    $package = Package::factory()->shipped()->create([
        'service' => 'USPS Ground Advantage',
        'service_evidence' => ServiceEvidence::Inferred,
        'service_inference_method' => 'usps-impb-stc',
        'service_ruleset_version' => '2026-06-24',
    ]);

    expect($package->recordInferredService(inference('Priority Mail', '2026-09-06')))->toBeTrue();

    $package->refresh();

    expect($package->service)->toBe('Priority Mail')
        ->and($package->service_ruleset_version)->toBe('2026-09-06');
});

it('leaves an inference from the same ruleset alone', function (): void {
    $package = Package::factory()->shipped()->create([
        'service' => 'USPS Ground Advantage',
        'service_evidence' => ServiceEvidence::Inferred,
        'service_inference_method' => 'usps-impb-stc',
        'service_ruleset_version' => '2026-09-06',
    ]);

    expect($package->recordInferredService(inference(version: '2026-09-06')))->toBeFalse();
});

it('refuses an inference from an older ruleset than the one on the package', function (): void {
    $package = Package::factory()->shipped()->create([
        'service' => 'Priority Mail',
        'service_evidence' => ServiceEvidence::Inferred,
        'service_inference_method' => 'usps-impb-stc',
        'service_ruleset_version' => '2026-09-06',
    ]);

    expect($package->recordInferredService(inference('USPS Ground Advantage', '2026-06-24')))->toBeFalse();

    $package->refresh();

    expect($package->service)->toBe('Priority Mail');
});

it('keeps an inferred service out of anything a channel publishes', function (): void {
    $package = Package::factory()->shipped()->create([
        'service' => null,
        'service_evidence' => ServiceEvidence::Unknown,
    ]);

    $package->recordInferredService(inference());

    expect($package->service)->toBe('USPS Ground Advantage')
        ->and($package->confirmedService())->toBeNull();
});

// The command runs over packages in bulk while shipping continues, so the guards
// have to hold against a write that lands between loading a package and writing
// to it -- not merely against the state the model was loaded with.
it('loses the race when the postage source confirms the service mid-run', function (): void {
    $package = Package::factory()->shipped()->create([
        'service' => null,
        'service_evidence' => ServiceEvidence::Unknown,
    ]);

    // Another process confirms it after this model was loaded.
    DB::table('packages')->where('id', $package->id)->update([
        'service' => 'Priority Mail Express',
        'service_evidence' => ServiceEvidence::Confirmed->value,
    ]);

    expect($package->recordInferredService(inference()))->toBeFalse();

    $fresh = Package::find($package->id);

    expect($fresh->service)->toBe('Priority Mail Express')
        ->and($fresh->service_evidence)->toBe(ServiceEvidence::Confirmed)
        ->and($fresh->service_inference_method)->toBeNull();
});

it('loses the race when a newer ruleset lands mid-run', function (): void {
    $package = Package::factory()->shipped()->create([
        'service' => 'USPS Ground Advantage',
        'service_evidence' => ServiceEvidence::Inferred,
        'service_inference_method' => 'usps-impb-stc',
        'service_ruleset_version' => '2026-06-24',
    ]);

    DB::table('packages')->where('id', $package->id)->update([
        'service' => 'Parcel Select',
        'service_ruleset_version' => '2026-12-01',
    ]);

    expect($package->recordInferredService(inference('Priority Mail', '2026-09-06')))->toBeFalse();

    expect(Package::find($package->id)->service)->toBe('Parcel Select');
});

it('writes nothing for a package that was never persisted', function (): void {
    expect((new Package(['service_evidence' => ServiceEvidence::Unknown]))->recordInferredService(inference()))
        ->toBeFalse();
});
