<?php

namespace App\Services\ServiceInference;

/**
 * A USPS Intelligent Mail package barcode number, parsed and validated.
 *
 * The 22-digit form is a 2-digit application identifier, a 3-digit Service Type
 * Code, a 9-digit Mailer ID, a 7-digit serial and a mod-10 check digit. Human
 * transcriptions and some carrier APIs prefix it with the GS1 `420` application
 * identifier and the destination ZIP, which is stripped here.
 *
 * Validation is not decoration. A mistyped number with plausible digits in the
 * service position is the one way the tracking-number rung produces a confident
 * wrong answer instead of no answer, so nothing reads `serviceTypeCode()` without
 * the check digit having passed first.
 */
readonly class ImpbTrackingNumber
{
    private function __construct(
        public string $digits,
        public string $serviceTypeCode,
    ) {}

    /**
     * Parse a tracking number, or return null if it is not a valid IMpb.
     */
    public static function tryParse(?string $trackingNumber): ?self
    {
        $digits = preg_replace('/\D/', '', $trackingNumber ?? '') ?? '';

        // GS1 AI 420 plus a 5-digit destination ZIP, as printed under the barcode.
        if (strlen($digits) === 30 && str_starts_with($digits, '420')) {
            $digits = substr($digits, 8);
        }

        if (strlen($digits) !== 22) {
            return null;
        }

        if (! self::checkDigitIsValid($digits)) {
            return null;
        }

        return new self($digits, substr($digits, 2, 3));
    }

    /**
     * USPS mod-10: weight 3 and 1 alternating, from the rightmost body digit.
     */
    private static function checkDigitIsValid(string $digits): bool
    {
        $body = substr($digits, 0, -1);
        $stated = (int) substr($digits, -1);

        $sum = 0;
        $weight = 3;

        foreach (array_reverse(str_split($body)) as $digit) {
            $sum += (int) $digit * $weight;
            $weight = $weight === 3 ? 1 : 3;
        }

        return $stated === (10 - ($sum % 10)) % 10;
    }
}
