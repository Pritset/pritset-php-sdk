<?php

declare(strict_types=1);

namespace Pritset;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Pritset\Http\Transport;
use Pritset\Resource\DocumentsClient;
use Pritset\Resource\TemplatesClient;

final class PritsetClient
{
    private readonly TemplatesClient $templates;
    private readonly DocumentsClient $documents;

    public function __construct(
        string $accessToken,
        string $secret,
        string $baseUrl = 'https://api.pritset.com',
        float $timeout = 30.0,
        ?ClientInterface $httpClient = null,
    ) {
        $transport = new Transport(
            accessToken: $accessToken,
            secret: $secret,
            baseUrl: $baseUrl,
            timeout: $timeout,
            httpClient: $httpClient ?? new Client(),
        );
        $this->templates = new TemplatesClient($transport);
        $this->documents = new DocumentsClient($transport);
    }

    /** @return array{client: string} */
    public function __debugInfo(): array
    {
        return ['client' => 'Pritset PHP SDK'];
    }

    public function templates(): TemplatesClient
    {
        return $this->templates;
    }

    public function documents(): DocumentsClient
    {
        return $this->documents;
    }
}
