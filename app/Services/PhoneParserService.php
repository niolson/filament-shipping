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

            // isValidNumber() matches the number against libphonenumber's metadata,
            // which trails NANP area code assignments — a perfectly dialable number
            // in a recently issued area code (370, an Ohio overlay) reads as invalid
            // and used to be discarded. Accept anything of a plausible length
            // instead: keeping a number we cannot fully verify beats dropping it,
            // because carriers that require a recipient phone reject the whole
            // shipment when it is missing (FedEx returns "phoneNumber cannot be
            // null"). Genuine garbage is still too short or too long to be possible.
            if (! $util->isPossibleNumber($phoneNumber)) {
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

    public static function nationalDigits(?string $phoneE164, ?string $defaultRegion = 'US'): ?string
    {
        if (! $phoneE164) {
            return null;
        }

        return self::parse($phoneE164, $defaultRegion)->phone;
    }

    /**
     * The digits to hand a carrier API, preferring the normalized national number
     * and falling back to the raw digits as they were entered.
     *
     * Rows stored before their number's area code reached libphonenumber's
     * metadata have a null phone_e164 that no amount of re-reading will recover,
     * so the unparsed value is the only phone number left to send.
     */
    public static function carrierDigits(?string $phoneE164, ?string $rawPhone, ?string $defaultRegion = 'US'): ?string
    {
        $nationalDigits = self::nationalDigits($phoneE164, $defaultRegion);

        if ($nationalDigits !== null) {
            return $nationalDigits;
        }

        $digits = preg_replace('/\D/', '', (string) $rawPhone);

        return $digits === '' ? null : $digits;
    }
}
