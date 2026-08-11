<?php

namespace App\Services;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

class PhoneParserService
{
    public static function parse(string $rawPhone, ?string $defaultRegion = 'US'): PhoneParseResult
    {
        $util = PhoneNumberUtil::getInstance();
        $defaultRegion = strtoupper($defaultRegion ?: 'US');

        try {
            $phoneNumber = $util->parse($rawPhone, $defaultRegion);

            if (! $util->isValidNumber($phoneNumber)) {
                return new PhoneParseResult(
                    phone: null,
                    e164: null,
                    extension: null,
                    error: "Invalid phone number: {$rawPhone}",
                );
            }

            $nationalNumber = (string) $phoneNumber->getNationalNumber();
            $e164 = $util->format($phoneNumber, PhoneNumberFormat::E164);
            $extension = $phoneNumber->getExtension();

            // Truncate extension to 6 chars (FedEx max)
            if ($extension !== null && $extension !== '') {
                $extension = substr($extension, 0, 6);
            } else {
                $extension = null;
            }

            return new PhoneParseResult(
                phone: $nationalNumber,
                e164: $e164,
                extension: $extension,
            );
        } catch (NumberParseException $e) {
            return new PhoneParseResult(
                phone: null,
                e164: null,
                extension: null,
                error: "Unable to parse phone number: {$rawPhone}",
            );
        }
    }

    /**
     * The national digits to hand a carrier API, preferring the stored E.164
     * value and falling back to the raw phone as entered.
     *
     * Deliberately more permissive than parse(): it asks only whether
     * libphonenumber can read the number and whether the result is a plausible
     * length, not whether isValidNumber() vouches for it. A number in an area
     * code newer than the bundled metadata (370, an Ohio overlay) fails
     * validation and so was never stored as phone_e164, but it dials fine — and
     * carriers that require a recipient phone reject the whole shipment when one
     * is missing (FedEx returns "phoneNumber cannot be null"). Sending digits we
     * cannot fully verify beats sending none. Anything libphonenumber cannot
     * parse, or that lands outside the length window, still yields null.
     */
    public static function carrierDigits(?string $phoneE164, ?string $rawPhone, ?string $defaultRegion = 'US'): ?string
    {
        $defaultRegion = strtoupper($defaultRegion ?: 'US');

        foreach ([$phoneE164, $rawPhone] as $candidate) {
            if (! filled($candidate)) {
                continue;
            }

            try {
                $phoneNumber = PhoneNumberUtil::getInstance()->parse($candidate, $defaultRegion);
                $nationalDigits = (string) $phoneNumber->getNationalNumber();

                if (strlen($nationalDigits) >= 7 && strlen($nationalDigits) <= 15) {
                    return $nationalDigits;
                }
            } catch (NumberParseException) {
                continue;
            }
        }

        return null;
    }
}
