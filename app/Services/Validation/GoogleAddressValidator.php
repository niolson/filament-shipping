<?php

namespace App\Services\Validation;

use App\Contracts\AddressValidationInterface;
use App\Enums\Deliverability;
use App\Events\AddressValidationFailed;
use App\Http\Integrations\Google\GoogleAddressValidationConnector;
use App\Http\Integrations\Google\GoogleAddressValidationProxyConnector;
use App\Http\Integrations\Google\Requests\ValidateAddress;
use App\Models\Shipment;
use Illuminate\Support\Facades\Log;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Connector;

/**
 * Universal fallback validator — supports every country. Placed after
 * UspsAddressValidator in the dispatch chain so USPS gets first crack at
 * US shipments; this catches non-US shipments and any US shipment USPS
 * couldn't attempt (no account, no license, etc).
 */
class GoogleAddressValidator implements AddressValidationInterface
{
    public function supports(string $country): bool
    {
        return true;
    }

    public function validate(Shipment $shipment): void
    {
        $connector = $this->resolveConnector();

        if ($connector === null) {
            // Not configured (no broker, no local API key) — not attempted.
            return;
        }

        $response = $this->fetchValidation($connector, $shipment);

        if ($response === null) {
            return;
        }

        $this->processResponse($shipment, $response);
    }

    protected function resolveConnector(): ?Connector
    {
        if (config('services.oauth.broker_url')) {
            return new GoogleAddressValidationProxyConnector;
        }

        if (config('services.google_address_validation.api_key')) {
            return new GoogleAddressValidationConnector;
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null Null when the request couldn't be attempted.
     */
    protected function fetchValidation(Connector $connector, Shipment $shipment): ?array
    {
        try {
            $request = new ValidateAddress;
            $request->body()->set([
                'address' => [
                    'regionCode' => $shipment->country ?? 'US',
                    'addressLines' => array_values(array_filter([$shipment->address1, $shipment->address2])),
                    'locality' => $shipment->city,
                    'administrativeArea' => $shipment->state_or_province,
                    'postalCode' => $shipment->postal_code,
                ],
            ]);

            Log::channel('google-validation')->debug('VALIDATION REQUEST', [
                'shipment_id' => $shipment->id,
            ]);

            $response = $connector->send($request);

            if (! $response->successful()) {
                Log::channel('google-validation')->warning('Google Address Validation request failed', [
                    'status' => $response->status(),
                    'shipment_id' => $shipment->id,
                ]);

                return null;
            }

            return $response->json();
        } catch (RequestException $e) {
            Log::channel('google-validation')->warning('Google Address Validation request failed', [
                'error' => $e->getMessage(),
                'shipment_id' => $shipment->id,
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function processResponse(Shipment $shipment, array $response): void
    {
        $result = $response['result'] ?? null;

        if ($result === null) {
            return;
        }

        Log::channel('google-validation')->debug('Google Address Validation Response', ['response' => $response]);

        $shipment->checked = true;

        $verdict = $result['verdict'] ?? [];
        $components = $result['address']['addressComponents'] ?? [];

        $hasSuspiciousComponent = collect($components)
            ->contains(fn (array $component) => ($component['confirmationLevel'] ?? null) === 'UNCONFIRMED_AND_SUSPICIOUS');

        [$deliverability, $message] = match (true) {
            $hasSuspiciousComponent || ! ($verdict['addressComplete'] ?? false) => [
                Deliverability::No, 'Address not confirmed deliverable',
            ],
            $verdict['hasUnconfirmedComponents'] ?? false => [
                Deliverability::Maybe, 'Address confirmed with unconfirmed components',
            ],
            default => [Deliverability::Yes, 'Address confirmed deliverable'],
        };

        $shipment->deliverability = $deliverability;
        $shipment->validation_message = $message;

        $postalAddress = $result['address']['postalAddress'] ?? [];
        $addressLines = $postalAddress['addressLines'] ?? [];
        $shipment->validated_address1 = $addressLines[0] ?? null;
        $shipment->validated_address2 = $addressLines[1] ?? null;
        $shipment->validated_city = $postalAddress['locality'] ?? null;
        $shipment->validated_state_or_province = $postalAddress['administrativeArea'] ?? null;
        $shipment->validated_postal_code = $postalAddress['postalCode'] ?? null;
        $shipment->validated_residential = $result['metadata']['residential'] ?? null;

        $shipment->save();

        if ($deliverability === Deliverability::No) {
            AddressValidationFailed::dispatch($shipment, $message);
        }
    }
}
