<?php

namespace App\Services;

use App\Http\Integrations\Gotenberg\GotenbergConnector;
use App\Http\Integrations\Gotenberg\Requests\HtmlToPdfRequest;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;

class GotenbergService
{
    public function __construct(private readonly GotenbergConnector $connector) {}

    /**
     * Render a Blade view to a PDF and return the raw PDF bytes.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws \RuntimeException if Gotenberg is not configured or the request fails
     */
    public function pdfFromView(string $view, array $data = []): string
    {
        $html = view($view, $data)->render();

        try {
            $response = $this->connector->send(new HtmlToPdfRequest($html));
            $response->throw();
        } catch (FatalRequestException $e) {
            throw new \RuntimeException('PDF renderer unavailable. Is Gotenberg running?', previous: $e);
        } catch (RequestException $e) {
            throw new \RuntimeException('PDF renderer returned an error: '.$e->getMessage(), previous: $e);
        }

        return $response->body();
    }
}
