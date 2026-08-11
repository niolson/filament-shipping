<?php

use App\Services\PhoneParserService;

it('parses a standard US number', function (): void {
    $result = PhoneParserService::parse('5125551234');

    expect($result->isValid())->toBeTrue()
        ->and($result->phone)->toBe('5125551234')
        ->and($result->e164)->toBe('+15125551234')
        ->and($result->extension)->toBeNull()
        ->and($result->error)->toBeNull();
});

it('parses a number with ext. format', function (): void {
    $result = PhoneParserService::parse('+1 210-728-4548 ext. 65440');

    expect($result->isValid())->toBeTrue()
        ->and($result->phone)->toBe('2107284548')
        ->and($result->e164)->toBe('+12107284548')
        ->and($result->extension)->toBe('65440')
        ->and($result->error)->toBeNull();
});

it('parses a number with x extension format', function (): void {
    $result = PhoneParserService::parse('512-555-1234 x1234');

    expect($result->isValid())->toBeTrue()
        ->and($result->phone)->toBe('5125551234')
        ->and($result->e164)->toBe('+15125551234')
        ->and($result->extension)->toBe('1234')
        ->and($result->error)->toBeNull();
});

it('parses an international format number', function (): void {
    $result = PhoneParserService::parse('+44 20 7946 0958');

    expect($result->isValid())->toBeTrue()
        ->and($result->phone)->toBe('2079460958')
        ->and($result->e164)->toBe('+442079460958')
        ->and($result->extension)->toBeNull()
        ->and($result->error)->toBeNull();
});

it('returns error for an invalid string', function (): void {
    $result = PhoneParserService::parse('not-a-phone');

    expect($result->isValid())->toBeFalse()
        ->and($result->phone)->toBeNull()
        ->and($result->e164)->toBeNull()
        ->and($result->extension)->toBeNull()
        ->and($result->error)->not->toBeNull();
});

it('truncates extension to 6 chars', function (): void {
    $result = PhoneParserService::parse('+1 210-728-4548 ext. 1234567890');

    expect($result->isValid())->toBeTrue()
        ->and($result->extension)->toBe('123456');
});

it('parses a formatted US number with dashes', function (): void {
    $result = PhoneParserService::parse('(512) 555-1234');

    expect($result->isValid())->toBeTrue()
        ->and($result->phone)->toBe('5125551234')
        ->and($result->e164)->toBe('+15125551234')
        ->and($result->extension)->toBeNull();
});

it('does not validate a number whose area code is newer than the bundled metadata', function (): void {
    $result = PhoneParserService::parse('370-579-7375');

    expect($result->isValid())->toBeFalse()
        ->and($result->phone)->toBeNull()
        ->and($result->e164)->toBeNull()
        ->and($result->error)->not->toBeNull();
});

it('falls back to the raw digits when no E164 value was stored', function (): void {
    expect(PhoneParserService::carrierDigits(null, '(370) 579-7375'))->toBe('3705797375');
});

it('normalizes possible carrier digits without including an extension', function (): void {
    expect(PhoneParserService::carrierDigits(null, '370-579-7375 ext. 321'))->toBe('3705797375');
});

it('returns national carrier digits for an international number', function (): void {
    expect(PhoneParserService::carrierDigits(null, '+44 20 7946 0958', 'GB'))->toBe('2079460958');
});

it('prefers the stored E164 value over the raw phone', function (): void {
    expect(PhoneParserService::carrierDigits('+15125551234', '000'))->toBe('5125551234');
});

it('returns no carrier digits when both phone values are empty', function (): void {
    expect(PhoneParserService::carrierDigits(null, null))->toBeNull()
        ->and(PhoneParserService::carrierDigits(null, 'unknown'))->toBeNull()
        ->and(PhoneParserService::carrierDigits(null, '123'))->toBeNull();
});

it('returns error for too-short number', function (): void {
    $result = PhoneParserService::parse('123');

    expect($result->isValid())->toBeFalse()
        ->and($result->phone)->toBeNull()
        ->and($result->e164)->toBeNull()
        ->and($result->error)->not->toBeNull();
});
