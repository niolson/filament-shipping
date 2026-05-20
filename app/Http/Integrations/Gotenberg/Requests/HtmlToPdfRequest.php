<?php

namespace App\Http\Integrations\Gotenberg\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Data\MultipartValue;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasMultipartBody;

class HtmlToPdfRequest extends Request implements HasBody
{
    use HasMultipartBody;

    protected Method $method = Method::POST;

    public function __construct(private readonly string $html) {}

    public function resolveEndpoint(): string
    {
        return '/forms/chromium/convert/html';
    }

    /**
     * @return array<MultipartValue>
     */
    protected function defaultBody(): array
    {
        return [
            new MultipartValue(
                name: 'files',
                value: $this->html,
                filename: 'index.html',
                headers: ['Content-Type' => 'text/html; charset=utf-8'],
            ),
            // Use print media so @media print rules (e.g. hiding the actions toolbar) apply
            new MultipartValue(name: 'emulatedMediaType', value: 'print'),
            new MultipartValue(name: 'marginTop', value: '0'),
            new MultipartValue(name: 'marginBottom', value: '0'),
            new MultipartValue(name: 'marginLeft', value: '0'),
            new MultipartValue(name: 'marginRight', value: '0'),
        ];
    }
}
