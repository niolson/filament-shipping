<?php

namespace App\Services\Carriers\Concerns;

use Saloon\Http\Response;

/**
 * Safely decodes a Saloon JSON response body into an array, falling back to the
 * raw body under a 'body' key when the payload is not valid JSON or not an array.
 */
trait DecodesJsonResponses
{
    /**
     * @return array<int|string, mixed>
     */
    private function decodeJsonSafely(Response $response): array
    {
        try {
            // Decode via json_decode (returns mixed) rather than Response::json()
            // (typed array): the is_array guard below is a genuine runtime check
            // for non-array JSON bodies, not a redundant one.
            $decoded = json_decode($response->body(), associative: true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded)
                ? $decoded
                : ['body' => $response->body()];
        } catch (\JsonException) {
            return ['body' => $response->body()];
        }
    }
}
